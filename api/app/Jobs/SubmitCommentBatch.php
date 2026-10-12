<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Models\CommentGeneration;
use App\Models\TermClosure;
use App\Support\Anthropic\Batches;
use App\Support\Comments\CommentFacts;
use App\Support\Comments\CommentPrompt;
use App\Support\CurrentInstitution;
use App\Support\SyncLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

/* Submits a term close's pending comments to the batch API (build plan 4.3, ADR 0007), dispatched
   after the close commits. Three steps, with no transaction held across the HTTP call:

   1. In one short transaction, a submitted generation is made for each pending comment that has
      none in flight. Generations a crashed earlier attempt made but never sent (no batch id) are
      superseded first, so a retry cannot leave two live requests for one comment unaccounted for.
   2. Outside any transaction, the batch is created, each request's custom_id being its
      generation's id. A crash after this and before step 3 means the retry submits again; the
      one rule (CommentGeneration::settle) applies only the latest generation, so the duplicate is
      harmless.
   3. In a second short transaction, the batch id is stored, and the collector is dispatched.

   Without an API key (development, CI) nothing is sent: each comment gets a templated draft
   labelled [Stand-in], through the same rule. */
class SubmitCommentBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $termClosureId,
        public readonly string $institutionId,
    ) {}

    public function handle(CurrentInstitution $current): void
    {
        $current->actingFor($this->institutionId, fn () => $this->run());
    }

    private function run(): void
    {
        $generations = $this->prepare();

        if ($generations->isEmpty()) {
            return;
        }

        $facts = $generations->mapWithKeys(fn (CommentGeneration $generation) => [
            $generation->id => CommentFacts::for($generation->comment->student_id, $generation->comment->year, $generation->comment->term),
        ]);

        if (blank(config('services.anthropic.key'))) {
            foreach ($generations as $generation) {
                SyncLog::transaction($this->institutionId, fn () => $generation->settle('succeeded', CommentPrompt::standIn($facts[$generation->id])));
            }

            return;
        }

        $batchId = Batches::fromConfig()->create($generations->map(fn (CommentGeneration $generation) => [
            'custom_id' => $generation->id,
            'params' => CommentPrompt::params($facts[$generation->id]),
        ])->values()->all());

        SyncLog::transaction($this->institutionId, fn () => CommentGeneration::query()
            ->whereIn('id', $generations->modelKeys())
            ->update(['batch_id' => $batchId]));

        CollectCommentBatch::dispatch($batchId, $this->institutionId)->delay(now()->addMinute());
    }

    /* Step 1. Nothing to do if the close has since been unwound (its comments are soft deleted). */
    private function prepare(): Collection
    {
        return SyncLog::transaction($this->institutionId, function (): Collection {
            $closure = TermClosure::query()->find($this->termClosureId);

            if ($closure === null) {
                return collect();
            }

            $comments = $closure->comments()->where('state', 'pending')->orderBy('id')->lockForUpdate()->get();

            CommentGeneration::query()
                ->whereIn('comment_id', $comments->modelKeys())
                ->where('status', 'submitted')
                ->whereNull('batch_id')
                ->update(['status' => 'superseded']);

            $inFlight = CommentGeneration::query()
                ->whereIn('comment_id', $comments->modelKeys())
                ->where('status', 'submitted')
                ->pluck('comment_id')
                ->all();

            return $comments
                ->reject(fn (Comment $comment) => in_array($comment->id, $inFlight, true))
                ->map(fn (Comment $comment) => $comment->generations()->create([
                    'institution_id' => $this->institutionId,
                    'status' => 'submitted',
                ])->setRelation('comment', $comment))
                ->values();
        });
    }

    /* Out of attempts: requests never sent cannot come back, so their comments fail and the
       teacher writes their own (the comments-failed notice is 4.3's failure-notice item). */
    public function failed(?Throwable $exception): void
    {
        app(CurrentInstitution::class)->actingFor($this->institutionId, fn () => $this->failUnsent($exception));
    }

    private function failUnsent(?Throwable $exception): void
    {

        $unsent = CommentGeneration::query()
            ->whereHas('comment', fn ($query) => $query->where('term_closure_id', $this->termClosureId))
            ->where('status', 'submitted')
            ->whereNull('batch_id')
            ->get();

        foreach ($unsent as $generation) {
            SyncLog::transaction($this->institutionId, fn () => $generation->settle('errored', error: 'not submitted: '.($exception?->getMessage() ?? 'unknown')));
        }
    }
}

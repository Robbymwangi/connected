<?php

namespace App\Jobs;

use App\Models\CommentGeneration;
use App\Support\Anthropic\Batches;
use App\Support\CurrentInstitution;
use App\Support\SyncLog;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/* Collects a comment batch (ADR 0007, decision 3): polling is this job releasing itself, not a
   scheduler. Until the batch has ended it releases itself with a growing delay; retryUntil() of
   26 hours, a little past the batch API's 24 hour expiry, overrides the worker's --tries, and
   maxExceptions bounds real errors. Once ended, the results are downloaded with no transaction
   open, then each is settled in its own short transaction under the one rule
   (CommentGeneration::settle). Results arrive in any order and are matched by custom_id, which is
   the generation's id. A generation the results do not mention counts as expired. */
class CollectCommentBatch implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 5;

    /** Seconds to wait before the next look, by attempt: one minute, then five, then fifteen, then every thirty. */
    private const DELAYS = [60, 300, 900, 1800];

    public function __construct(
        public readonly string $batchId,
        public readonly string $institutionId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(26);
    }

    public function handle(CurrentInstitution $current): void
    {
        $current->actingFor($this->institutionId, fn () => $this->run());
    }

    private function run(): void
    {
        $batches = Batches::fromConfig();
        $batch = $batches->retrieve($this->batchId);

        if ($batch['processing_status'] !== 'ended' || $batch['results_url'] === null) {
            $this->release(self::DELAYS[min($this->attempts() - 1, count(self::DELAYS) - 1)]);

            return;
        }

        foreach ($batches->results($batch['results_url']) as $result) {
            $customId = $result['custom_id'] ?? null;

            // custom_id comes back from outside; only one of ours, from this batch, is settled.
            $generation = is_string($customId) && Str::isUuid($customId)
                ? CommentGeneration::query()->where('batch_id', $this->batchId)->find($customId)
                : null;

            if ($generation !== null) {
                [$status, $text, $error] = self::outcome($result);
                SyncLog::transaction($this->institutionId, fn () => $generation->settle($status, $text, $error));
            }
        }

        $this->expireRemaining('the batch results did not include it');
    }

    public function failed(?Throwable $exception): void
    {
        $error = 'collection gave up: '.($exception?->getMessage() ?? 'retry window passed');

        app(CurrentInstitution::class)->actingFor($this->institutionId, fn () => $this->expireRemaining($error));
    }

    /**
     * What one result line means for its comment: text for a draft, or why there is none. A
     * succeeded request still fails if the model refused or ran out of tokens, or wrote no text;
     * thinking blocks, which come first on Claude Haiku 5.5, are skipped by type.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    public static function outcome(array $result): array
    {
        $type = $result['result']['type'] ?? 'errored';

        if ($type === 'expired') {
            return ['expired', null, 'the batch expired before this request ran'];
        }

        if ($type !== 'succeeded') {
            $reason = $result['result']['error']['error']['type'] ?? $type;

            return ['errored', null, (string) $reason];
        }

        $message = $result['result']['message'] ?? [];
        $stop = $message['stop_reason'] ?? null;

        if (! in_array($stop, ['end_turn', 'stop_sequence'], true)) {
            return ['errored', null, 'stopped: '.($stop ?? 'unknown')];
        }

        $text = trim(collect($message['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n"));

        return $text === '' ? ['errored', null, 'no text in the response'] : ['succeeded', $text, null];
    }

    private function expireRemaining(string $error): void
    {
        $remaining = CommentGeneration::query()
            ->where('batch_id', $this->batchId)
            ->where('status', 'submitted')
            ->get();

        foreach ($remaining as $generation) {
            SyncLog::transaction($this->institutionId, fn () => $generation->settle('expired', error: $error));
        }
    }
}

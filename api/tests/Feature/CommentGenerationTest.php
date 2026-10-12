<?php

use App\Jobs\CollectCommentBatch;
use App\Jobs\SubmitCommentBatch;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\CommentGeneration;
use App\Models\Mark;
use App\Models\TermClosure;
use App\Support\Comments\CommentFacts;
use App\Support\Comments\CommentPrompt;
use App\Support\CurrentInstitution;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/* 4.3, comment generation (ADR 0007, docs/spec/workflow.md, Comments): the submit job sends a term
   close's pending comments to the batch API, or writes labelled stand-ins without a key; the
   collector polls by releasing itself and settles each result under the one rule. Every test
   refuses real HTTP. */

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.anthropic.key' => null, 'services.anthropic.base_url' => 'https://anthropic.test']);
});

/**
 * A stream with Maths at EE in Term 1 (the graph's 8 of 10) and AE in Term 2 (5 of 10), Term 2
 * closed with its comments pending.
 *
 * @return array{0: array, 1: TermClosure}
 */
function termTwoClosed(int $students = 1): array
{
    $g = buildGraph('Hillside Academy', '-gen-'.Str::random(6));
    $g['assessment']->finalize($g['teacher']);
    $termTwo = Assessment::create([
        'institution_id' => $g['institution']->id, 'class_id' => $g['class']->id, 'subject_id' => $g['subject']->id,
        'name' => 'Mid Term', 'term' => 2, 'year' => 2026, 'date' => '2026-06-01', 'status' => 'scheduled', 'created_by' => $g['teacher']->id,
    ]);

    $everyone = [$g['student'], ...array_map(fn (int $n) => enrolledStudent($g, "Pupil {$n}"), $students > 1 ? range(2, $students) : [])];
    foreach ($everyone as $student) {
        Mark::create([
            'institution_id' => $g['institution']->id, 'assessment_id' => $termTwo->id, 'student_id' => $student->id,
            'criterion_id' => $g['criterion']->id, 'mark_kind' => 'score', 'score' => 5, 'last_edited_by' => $g['teacher']->id,
        ]);
    }
    $termTwo->finalize($g['teacher']);

    // Both jobs are run by hand here; faking them keeps the sync queue from running them on dispatch.
    Bus::fake([SubmitCommentBatch::class, CollectCommentBatch::class]);
    $closure = TermClosure::close($g['teacher'], $g['class'], 2026, 2)['closure'];
    $g['termTwo'] = $termTwo;
    $g['students'] = $everyone;

    return [$g, $closure];
}

function submit(TermClosure $closure): void
{
    (new SubmitCommentBatch($closure->id, $closure->institution_id))->handle(app(CurrentInstitution::class));
}

function batchResult(CommentGeneration $generation, array $result): array
{
    return ['custom_id' => $generation->id, 'result' => $result];
}

function succeeded(string $text, string $stop = 'end_turn'): array
{
    return ['type' => 'succeeded', 'message' => [
        'stop_reason' => $stop,
        'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => $text]],
    ]];
}

function fakeEndedBatch(string $batchId, array $lines): void
{
    Http::fake([
        "anthropic.test/v1/messages/batches/{$batchId}" => Http::response(['id' => $batchId, 'processing_status' => 'ended', 'results_url' => "https://anthropic.test/v1/messages/batches/{$batchId}/results"]),
        "anthropic.test/v1/messages/batches/{$batchId}/results" => Http::response(implode("\n", array_map('json_encode', $lines))),
    ]);
}

test('the facts and the prompt carry levels and movement only, about "the student"', function () {
    [$g] = termTwoClosed();

    $facts = CommentFacts::for($g['student']->id, 2026, 2);

    expect(CommentPrompt::describe($facts))->toBe("Term 2 of 2026.\nMaths: Approaching Expectations (down since Term 1 of 2026, when it was Exceeding Expectations).");
    expect(CommentPrompt::params($facts))->toMatchArray(['model' => 'claude-haiku-5-5', 'max_tokens' => 1024, 'output_config' => ['effort' => 'low']]);
});

test('without a key, each pending comment becomes a labelled stand-in draft and nothing is sent', function () {
    [$g, $closure] = termTwoClosed();
    $comment = $closure->comments()->sole();

    submit($closure);

    $comment->refresh();
    expect($comment->only(['state', 'author_id', 'version']))->toBe(['state' => 'draft', 'author_id' => null, 'version' => 2]);
    expect($comment->body)->toBe('[Stand-in] The student is Approaching Expectations in Maths.');
    expect($comment->generations()->sole()->status)->toBe('succeeded');
    expect(DB::table('sync_changes')->where('record_id', $comment->id)->where('version', 2)->exists())->toBeTrue();
    Http::assertNothingSent();
});

test('with a key, the batch is created with one request per comment, keyed by generation, and nothing identifying in it', function () {
    config(['services.anthropic.key' => 'sk-test']);
    Http::fake(['anthropic.test/v1/messages/batches' => Http::response(['id' => 'msgbatch_1', 'processing_status' => 'in_progress'])]);
    [$g, $closure] = termTwoClosed(students: 2);

    submit($closure);

    $generations = CommentGeneration::query()->orderBy('id')->get();
    expect($generations)->toHaveCount(2);
    expect($generations->pluck('batch_id')->unique()->all())->toBe(['msgbatch_1']);
    Http::assertSent(function (Request $request) use ($generations, $g) {
        $body = $request->body();

        return $request->url() === 'https://anthropic.test/v1/messages/batches'
            && $request->hasHeader('x-api-key', 'sk-test')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && collect($request['requests'])->pluck('custom_id')->sort()->values()->all() === $generations->pluck('id')->sort()->values()->all()
            && ! str_contains($body, $g['student']->name)
            && ! str_contains($body, 'Pupil 2')
            && ! str_contains($body, 'Hillside')
            && ! str_contains($body, $g['student']->id)
            && ! str_contains($body, '%');
    });
    Bus::assertDispatched(CollectCommentBatch::class, fn (CollectCommentBatch $job) => $job->batchId === 'msgbatch_1');
    expect(Comment::query()->pluck('state')->unique()->all())->toBe(['pending']);
});

test('a retry supersedes generations that were never sent, and a comment already in flight is not sent again', function () {
    config(['services.anthropic.key' => 'sk-test']);
    Http::fake(['anthropic.test/v1/messages/batches' => Http::response(['id' => 'msgbatch_2'])]);
    [$g, $closure] = termTwoClosed(students: 2);
    [$first, $second] = $closure->comments()->orderBy('id')->get()->all();
    $unsent = $first->generations()->create(['institution_id' => $g['institution']->id, 'status' => 'submitted']);
    $inFlight = $second->generations()->create(['institution_id' => $g['institution']->id, 'status' => 'submitted', 'batch_id' => 'msgbatch_earlier']);

    submit($closure);

    expect($unsent->fresh()->status)->toBe('superseded');
    expect($inFlight->fresh()->batch_id)->toBe('msgbatch_earlier');
    expect($first->generations()->where('status', 'submitted')->sole()->batch_id)->toBe('msgbatch_2');
    expect($second->generations()->count())->toBe(1);
});

test('an unwound close submits nothing', function () {
    [$g, $closure] = termTwoClosed();
    $g['teacher']->update(['is_admin' => true]);
    $g['termTwo']->unlock($g['teacher']);

    submit($closure);

    $this->assertDatabaseCount('comment_generations', 0);
});

test('the collector releases itself, with a growing delay, until the batch has ended', function () {
    config(['services.anthropic.key' => 'sk-test']);
    Http::fake(['anthropic.test/v1/messages/batches/msgbatch_3' => Http::response(['id' => 'msgbatch_3', 'processing_status' => 'in_progress', 'results_url' => null])]);
    [$g] = termTwoClosed();

    $job = (new CollectCommentBatch('msgbatch_3', $g['institution']->id))->withFakeQueueInteractions();
    $job->handle(app(CurrentInstitution::class));

    $job->assertReleased(60);
    expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(25)->getTimestamp());
});

test('an ended batch settles each result by custom_id: text becomes a draft, and an error, expiry, refusal, or omission fails', function () {
    config(['services.anthropic.key' => 'sk-test']);
    [$g, $closure] = termTwoClosed(students: 5);
    $comments = $closure->comments()->orderBy('id')->get();
    $generations = $comments->map(fn (Comment $comment) => $comment->generations()->create([
        'institution_id' => $g['institution']->id, 'status' => 'submitted', 'batch_id' => 'msgbatch_4',
    ]));
    fakeEndedBatch('msgbatch_4', [
        batchResult($generations[3], ['type' => 'errored', 'error' => ['type' => 'error', 'error' => ['type' => 'invalid_request_error']]]),
        batchResult($generations[0], succeeded('A steady term in Maths.')),
        batchResult($generations[1], ['type' => 'expired']),
        batchResult($generations[2], succeeded('', 'refusal')),
        ['custom_id' => 'not-one-of-ours', 'result' => succeeded('Ignored.')],
    ]);

    (new CollectCommentBatch('msgbatch_4', $g['institution']->id))->handle(app(CurrentInstitution::class));

    expect($comments->map(fn (Comment $comment) => $comment->fresh()->only(['state', 'body']))->all())->toBe([
        ['state' => 'draft', 'body' => 'A steady term in Maths.'],
        ['state' => 'failed', 'body' => null],
        ['state' => 'failed', 'body' => null],
        ['state' => 'failed', 'body' => null],
        ['state' => 'failed', 'body' => null],
    ]);
    expect($generations->map(fn (CommentGeneration $generation) => $generation->fresh()->status)->all())
        ->toBe(['succeeded', 'expired', 'errored', 'errored', 'expired']);
    expect($generations[2]->fresh()->error)->toBe('stopped: refusal');
});

test('a result applies only to the latest generation of a live, pending comment', function (string $case) {
    config(['services.anthropic.key' => 'sk-test']);
    [$g, $closure] = termTwoClosed();
    $comment = $closure->comments()->sole();
    $generation = $comment->generations()->create(['institution_id' => $g['institution']->id, 'status' => 'submitted', 'batch_id' => 'msgbatch_5']);

    $this->travel(1)->seconds();
    match ($case) {
        'regenerated since' => $comment->generations()->create(['institution_id' => $g['institution']->id, 'status' => 'submitted', 'batch_id' => 'msgbatch_6']),
        'edited since' => $comment->update(['state' => 'draft', 'body' => 'Written by the teacher.', 'author_id' => $g['teacher']->id]),
        'unlocked since' => $comment->delete(),
    };
    $before = Comment::withTrashed()->find($comment->id)->only(['state', 'body', 'version']);
    fakeEndedBatch('msgbatch_5', [batchResult($generation, succeeded('Too late.'))]);

    (new CollectCommentBatch('msgbatch_5', $g['institution']->id))->handle(app(CurrentInstitution::class));

    expect($generation->fresh()->status)->toBe('superseded');
    expect(Comment::withTrashed()->find($comment->id)->only(['state', 'body', 'version']))->toBe($before);
})->with(['regenerated since', 'edited since', 'unlocked since']);

test('when collection gives up, what is still submitted expires and its comments fail', function () {
    [$g, $closure] = termTwoClosed();
    $comment = $closure->comments()->sole();
    $generation = $comment->generations()->create(['institution_id' => $g['institution']->id, 'status' => 'submitted', 'batch_id' => 'msgbatch_7']);

    (new CollectCommentBatch('msgbatch_7', $g['institution']->id))->failed(new RuntimeException('timed out'));

    expect($generation->fresh()->only(['status', 'error']))->toBe(['status' => 'expired', 'error' => 'collection gave up: timed out']);
    expect($comment->fresh()->state)->toBe('failed');
});

test('a job runs as its institution and leaves the caller\'s institution as it was', function () {
    [, $closure] = termTwoClosed();
    $other = buildGraph('Other School', '-gen-other');
    app(CurrentInstitution::class)->set($other['institution']->id);

    submit($closure);

    expect(app(CurrentInstitution::class)->id())->toBe($other['institution']->id);
    expect(Comment::withoutGlobalScopes()->find($closure->comments()->withoutGlobalScopes()->sole()->id)->state)->toBe('draft');
});

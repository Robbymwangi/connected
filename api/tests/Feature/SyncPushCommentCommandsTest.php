<?php

use App\Jobs\SubmitCommentBatch;
use App\Models\Comment;
use App\Models\CommentGeneration;
use App\Support\SyncLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* 4.3, comment commands over POST /sync: edit, accept, and regenerate, each answered only at the comment's own
   version and checked against its stored state (docs/spec/sync-protocol.md, "Comment commands"). */

/** The graph's term closed, and its one comment put in the given state. */
function commentIn(array $graph, string $state): Comment
{
    $comment = closeGraphTerm($graph)->comments()->sole();

    if ($state !== 'pending') {
        $comment->update(['state' => $state, 'body' => in_array($state, ['draft', 'accepted'], true) ? 'Generated text.' : null]);
    }

    return $comment->fresh();
}

function commentCommand(Comment $comment, array $fields, ?int $base = null, ?string $id = null): array
{
    return pushEntry('comments', $comment->id, $base ?? $comment->version, $fields, $id);
}

function by(array $graph, array $extra = []): array
{
    return ['byId' => $graph['teacher']->id, ...$extra];
}

test('each command acts only on the states it is for', function (string $state, string $command, string $expected) {
    $g = buildGraph('School A', '-cc-'.$state.'-'.$command);
    $comment = commentIn($g, $state);
    $fields = [$command => by($g, $command === 'edit' ? ['body' => 'Written by the teacher.'] : [])];

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [commentCommand($comment, $fields)]))[0];

    expect($result['status'])->toBe($expected);
})->with([
    ['pending', 'edit', 'accepted'], ['draft', 'edit', 'accepted'], ['failed', 'edit', 'accepted'], ['accepted', 'edit', 'invalid'],
    ['pending', 'accept', 'invalid'], ['draft', 'accept', 'accepted'], ['failed', 'accept', 'invalid'], ['accepted', 'accept', 'invalid'],
    ['pending', 'regenerate', 'accepted'], ['draft', 'regenerate', 'accepted'], ['failed', 'regenerate', 'accepted'], ['accepted', 'regenerate', 'invalid'],
]);

test('an edit makes a draft with the editor as author, trimmed, logged, and supersedes what is in flight', function () {
    $g = buildGraph();
    $comment = commentIn($g, 'pending');
    $inFlight = $comment->generations()->create(['institution_id' => $g['institution']->id, 'status' => 'submitted', 'batch_id' => 'msgbatch_x']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [commentCommand($comment, ['edit' => by($g, ['body' => '  A careful, steady term.  '])])]))[0];

    expect($result)->toMatchArray(['status' => 'accepted', 'version' => 2]);
    expect($comment->fresh()->only(['body', 'state', 'author_id']))->toBe(['body' => 'A careful, steady term.', 'state' => 'draft', 'author_id' => $g['teacher']->id]);
    expect($inFlight->fresh()->status)->toBe('superseded');
    expect(SyncLog::transaction($g['institution']->id, fn () => $inFlight->fresh()->settle('succeeded', 'Too late.')))->toBeFalse();
    expect(DB::table('sync_changes')->where('record_id', $comment->id)->where('version', 2)->exists())->toBeTrue();
});

test('accepting a draft records the acceptor as author', function () {
    $g = buildGraph();
    $other = makeColleague($g, 'acceptor@example.com');
    $comment = commentIn($g, 'draft');

    $result = postedResults(pushEntries($this, tokenFor($other), [commentCommand($comment, ['accept' => ['byId' => $other->id]])]))[0];

    expect($result['status'])->toBe('accepted');
    expect($comment->fresh()->only(['body', 'state', 'author_id']))->toBe(['body' => 'Generated text.', 'state' => 'accepted', 'author_id' => $other->id]);
});

test('regenerating clears the text, supersedes what is in flight, and queues generation for the close once', function () {
    $g = buildGraph();
    $comment = commentIn($g, 'draft');
    $inFlight = $comment->generations()->create(['institution_id' => $g['institution']->id, 'status' => 'submitted', 'batch_id' => 'msgbatch_y']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [commentCommand($comment, ['regenerate' => by($g)])]))[0];

    expect($result['status'])->toBe('accepted');
    expect($comment->fresh()->only(['body', 'state', 'author_id']))->toBe(['body' => null, 'state' => 'pending', 'author_id' => null]);
    expect($inFlight->fresh()->status)->toBe('superseded');
    Bus::assertDispatched(SubmitCommentBatch::class, fn (SubmitCommentBatch $job) => $job->termClosureId === $comment->term_closure_id);
    Bus::assertDispatchedTimes(SubmitCommentBatch::class, 2); // the close's own, and this one
});

test('a stale base is answered with the comment as it stands and writes nothing', function () {
    $g = buildGraph();
    $comment = commentIn($g, 'draft');
    $seen = $comment->version;
    $comment->update(['body' => 'Changed by someone else.']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [commentCommand($comment, ['accept' => by($g)], base: $seen)]))[0];

    expect($result['status'])->toBe('conflict');
    expect($result['current']['fields'])->toMatchArray(['body' => 'Changed by someone else.', 'state' => 'draft']);
    expect($comment->fresh()->state)->toBe('draft');
});

test('a base ahead of the server is invalid', function () {
    $g = buildGraph();
    $comment = commentIn($g, 'draft');

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [commentCommand($comment, ['accept' => by($g)], base: $comment->version + 1)]))[0];

    expect($result['status'])->toBe('invalid');
});

test('a malformed command is invalid and writes nothing', function (array $fields, string $reason) {
    $g = buildGraph('School A', '-cc-bad-'.md5(json_encode($fields)));
    $comment = commentIn($g, 'draft');
    $fields = json_decode(str_replace('ME', $g['teacher']->id, json_encode($fields)), true);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [commentCommand($comment, $fields)]))[0];

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toContain($reason);
    expect($comment->fresh()->version)->toBe($comment->version);
})->with([
    'someone else as byId' => [['accept' => ['byId' => '00000000-0000-4000-8000-000000000000']], 'byId must be the signed-in user'],
    'no byId' => [['accept' => []], 'accept carries exactly: byId'],
    'an extra key' => [['accept' => ['byId' => 'ME', 'note' => 'x']], 'accept carries exactly: byId'],
    'two commands' => [['accept' => ['byId' => 'ME'], 'regenerate' => ['byId' => 'ME']], 'sent on its own'],
    'a blank body' => [['edit' => ['byId' => 'ME', 'body' => "  \n "]], 'at most 600 characters'],
    'a body over 600 characters' => [['edit' => ['byId' => 'ME', 'body' => str_repeat('a', 601)]], 'at most 600 characters'],
    'a body with NUL' => [['edit' => ['byId' => 'ME', 'body' => "a\0b"]], 'at most 600 characters'],
    'a field patch' => [['body' => 'Patched.'], 'never by field'],
]);

test('a body of exactly 600 characters is accepted', function () {
    $g = buildGraph();
    $comment = commentIn($g, 'draft');

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [commentCommand($comment, ['edit' => by($g, ['body' => str_repeat('a', 600)])])]))[0];

    expect($result['status'])->toBe('accepted');
});

test('a comment withdrawn by an unlock is invalid', function () {
    $g = buildGraph();
    $comment = commentIn($g, 'draft');
    $comment->delete();

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [commentCommand($comment->fresh(), ['accept' => by($g)])]))[0];

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBe('the comment has been withdrawn by an unlock');
});

test('another school\'s comment is forbidden, and an unknown one at base 0 is invalid', function () {
    $a = buildGraph('School A', '-cc-a');
    $b = buildGraph('School B', '-cc-b');
    $comment = commentIn($b, 'draft');

    $results = postedResults(pushEntries($this, tokenFor($a['teacher']), [
        commentCommand($comment, ['accept' => by($a)]),
        pushEntry('comments', Str::uuid7()->toString(), 0, ['accept' => by($a)]),
    ]));

    expect(array_column($results, 'status'))->toBe(['forbidden', 'invalid']);
    expect($results[1]['reason'])->toBe('comments are created by the term close');
    expect($comment->fresh()->state)->toBe('draft');
});

test('a resend replays the stored outcome', function () {
    $g = buildGraph();
    $comment = commentIn($g, 'draft');
    $entry = commentCommand($comment, ['accept' => by($g)]);

    $first = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];
    $again = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($again)->toBe([...$first, 'replayed' => true]);
    expect(Comment::query()->sole()->version)->toBe($comment->version + 1);
    expect(CommentGeneration::query()->count())->toBe(0);
});

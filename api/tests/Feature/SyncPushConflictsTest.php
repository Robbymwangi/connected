<?php

use App\Models\Conflict;
use App\Support\CurrentInstitution;
use App\Sync\Push\MarkConflicts;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/* 3.2b, C4: a conflict on a mark is a record both teachers and a moderator can see, with the
   two sides side by side (ADR 0002, docs/spec/sync-protocol.md). These are the spec's worked
   examples as tests: two devices edit one cell, two enter the same value, two create the same
   cell, and an update against a delete. Side A is the write that produced the current version,
   found by version order and the change it wrote, never by anyone's `at`. */

const UTC_STAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/';

/** The conflicts for a mark, oldest first, with the JSON columns decoded. */
function conflictsFor(string $markId): array
{
    return DB::table('conflicts')->where('mark_id', $markId)->orderBy('id')->get()->map(fn ($row) => [
        ...(array) $row,
        'side_a' => json_decode($row->side_a, true),
        'side_b' => json_decode($row->side_b, true),
        'proposals' => json_decode($row->proposals, true),
        'resolution' => $row->resolution === null ? null : json_decode($row->resolution, true),
    ])->all();
}

/** received_at of the change-log row at a version, as the history formats it. */
function loggedAt(string $recordId, int $version): ?string
{
    return DB::selectOne(
        <<<'SQL'
        select to_char(received_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') as stamp
        from sync_changes where record_id = ? and version = ?
        SQL,
        [$recordId, $version],
    )?->stamp;
}

/** Device A sets the graph's mark to a score at base 1, accepted at version 2. */
function deviceAScores(mixed $test, array $graph, int $score = 6, string $at = '2026-10-07T08:00:00Z'): array
{
    $entry = pushEntry('marks', $graph['mark']->id, 1, ['score' => $score], at: $at);
    $result = postedResults(pushEntries($test, tokenFor($graph['teacher']), [$entry]))[0];
    expect($result['status'])->toBe('accepted');
    app('auth')->forgetGuards();

    return $entry;
}

test('two devices edit one cell with different values: a conflict, the cell unchanged, both sides recorded', function () {
    $g = buildGraph('School A', '-cf-two');
    $b = makeColleague($g, 'b-cf-two@example.com');
    $a = deviceAScores($this, $g, 6);
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9], at: '2026-10-07T07:59:00Z');

    $result = postedResults(pushEntries($this, tokenFor($b), [$entry]))[0];

    expect($result['status'])->toBe('conflict');
    expect($result['conflictId'])->toBeString();
    expect($result['current']['version'])->toBe(2);
    expect($result['current']['fields']['score'])->toBe(6);
    expect(DB::table('marks')->where('id', $g['mark']->id)->value('score'))->toBe(6);

    $conflicts = conflictsFor($g['mark']->id);
    expect($conflicts)->toHaveCount(1);
    $conflict = $conflicts[0];
    expect($conflict['id'])->toBe($result['conflictId']);
    expect($conflict['base_version'])->toBe(1);
    expect($conflict['proposals'])->toBe([]);
    expect($conflict['referral'])->toBeNull();
    expect($conflict['resolution'])->toBeNull();
    expect($conflict['resolved_at'])->toBeNull();

    // jsonb stores keys in its own order, so the sides are compared as sets of pairs.
    expect($conflict['side_a'])->toEqual([
        'editId' => $a['id'], 'userId' => $g['teacher']->id, 'who' => 'T. Teacher',
        'markKind' => 'score', 'score' => 6, 'at' => '2026-10-07T08:00:00Z', 'receivedAt' => loggedAt($g['mark']->id, 2),
    ]);
    expect($conflict['side_b'])->toMatchArray([
        'editId' => $entry['id'], 'userId' => $b->id, 'who' => 'A. Colleague',
        'markKind' => 'score', 'score' => 9, 'at' => '2026-10-07T07:59:00Z',
    ]);
    expect($conflict['side_b']['receivedAt'])->toMatch(UTC_STAMP);
    expect($conflict['side_b']['receivedAt'] >= $conflict['side_a']['receivedAt'])->toBeTrue();

    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('conflict_id'))->toBe($conflict['id']);
});

test('two devices enter the same value: accepted at the unchanged version, credit kept, an auto conflict recorded', function () {
    $g = buildGraph('School A', '-cf-same');
    $b = makeColleague($g, 'b-cf-same@example.com');
    deviceAScores($this, $g, 6);
    $logRows = DB::table('sync_changes')->where('record_id', $g['mark']->id)->count();
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 6]);

    $result = postedResults(pushEntries($this, tokenFor($b), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 2]);
    expect(DB::table('marks')->where('id', $g['mark']->id)->first())->toMatchArray(['version' => 2, 'last_edited_by' => $g['teacher']->id]);
    expect(DB::table('sync_changes')->where('record_id', $g['mark']->id)->count())->toBe($logRows);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->first())->toMatchArray(['status' => 'accepted', 'version' => 2, 'conflict_id' => null, 'change_seq' => null]);

    $conflicts = conflictsFor($g['mark']->id);
    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0]['resolution'])->toBe(['kind' => 'auto']);
    expect($conflicts[0]['resolved_at'])->not->toBeNull();
    expect($conflicts[0]['side_b']['editId'])->toBe($entry['id']);

    app('auth')->forgetGuards();
    $resend = postedResults(pushEntries($this, tokenFor($b), [$entry]))[0];
    expect($resend)->toBe($result + ['replayed' => true]);
    expect(conflictsFor($g['mark']->id))->toHaveCount(1);
});

test('a forged earlier at gains no credit: attribution is by version order, never by at', function () {
    $g = buildGraph('School A', '-cf-forged');
    $b = makeColleague($g, 'b-cf-forged@example.com');
    $a = deviceAScores($this, $g, 6, at: '2026-10-07T08:00:00Z');
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 6], at: '2000-01-01T00:00:00Z');

    postedResults(pushEntries($this, tokenFor($b), [$entry]));

    expect(DB::table('marks')->where('id', $g['mark']->id)->value('last_edited_by'))->toBe($g['teacher']->id);
    $conflict = conflictsFor($g['mark']->id)[0];
    expect($conflict['side_a']['editId'])->toBe($a['id']);
    expect($conflict['side_b']['at'])->toBe('2000-01-01T00:00:00Z');
    expect($conflict['side_a']['receivedAt'] < $conflict['side_b']['receivedAt'])->toBeTrue();
});

test('the auto conflict does not block finalizing the assessment', function () {
    $g = buildGraph('School A', '-cf-finalize');
    $b = makeColleague($g, 'b-cf-finalize@example.com');
    deviceAScores($this, $g, 6);
    postedResults(pushEntries($this, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => 6])]));

    expect(Conflict::query()->whereNull('resolved_at')->count())->toBe(0);

    app(CurrentInstitution::class)->reset();
    $g['assessment']->finalize($g['teacher']);

    expect($g['assessment']->fresh()->status)->toBe('finalized');
});

test('two devices create one cell with different values: a conflict at base 0, side A the first create', function () {
    $g = buildGraph('School A', '-cf-create-diff');
    $b = makeColleague($g, 'b-cf-create-diff@example.com');
    $criterion = newCriterion($g);
    $first = markCreate($g, $g['student'], $criterion, ['markKind' => 'score', 'score' => 5]);
    $second = markCreate($g, $g['student'], $criterion, ['markKind' => 'score', 'score' => 7]);

    postedResults(pushEntries($this, tokenFor($g['teacher']), [$first]));
    app('auth')->forgetGuards();
    $result = postedResults(pushEntries($this, tokenFor($b), [$second]))[0];

    expect($result['status'])->toBe('conflict');
    $conflict = conflictsFor($first['recordId'])[0];
    expect($conflict['base_version'])->toBe(0);
    expect($conflict['side_a'])->toMatchArray(['editId' => $first['id'], 'userId' => $g['teacher']->id, 'score' => 5]);
    expect($conflict['side_b'])->toMatchArray(['editId' => $second['id'], 'userId' => $b->id, 'score' => 7]);
});

test('two devices create one cell with the same value: accepted, credit to the first, an auto conflict', function () {
    $g = buildGraph('School A', '-cf-create-same');
    $b = makeColleague($g, 'b-cf-create-same@example.com');
    $criterion = newCriterion($g);
    $first = markCreate($g, $g['student'], $criterion, ['markKind' => 'score', 'score' => 5]);
    $second = markCreate($g, $g['student'], $criterion, ['markKind' => 'score', 'score' => 5]);

    postedResults(pushEntries($this, tokenFor($g['teacher']), [$first]));
    app('auth')->forgetGuards();
    $result = postedResults(pushEntries($this, tokenFor($b), [$second]))[0];

    expect($result)->toBe(['id' => $second['id'], 'status' => 'accepted', 'version' => 1]);
    expect(DB::table('marks')->where('id', $first['recordId'])->value('last_edited_by'))->toBe($g['teacher']->id);
    expect(conflictsFor($first['recordId'])[0]['resolution'])->toBe(['kind' => 'auto']);
});

test('a mark made outside sync has no mutation, so side A gets a derived editId and the credited user', function () {
    $g = buildGraph('School A', '-cf-fallback');
    $b = makeColleague($g, 'b-cf-fallback@example.com');
    $entry = markCreate($g, $g['student'], $g['criterion'], ['markKind' => 'score', 'score' => 3]);

    postedResults(pushEntries($this, tokenFor($b), [$entry]));

    $sideA = conflictsFor($g['mark']->id)[0]['side_a'];
    expect($sideA['editId'])->toBe(Uuid::uuid5(MarkConflicts::EDIT_ID_NAMESPACE, "marks:{$g['mark']->id}:1")->toString());
    expect($sideA['userId'])->toBe($g['teacher']->id);
    expect($sideA['at'])->toBeNull();
    expect($sideA['receivedAt'])->toBe(loggedAt($g['mark']->id, 1));
});

test('an update against a deleted mark is a conflict with no conflict record, and the row is untouched', function () {
    $g = buildGraph('School A', '-cf-deleted');
    $g['mark']->delete();
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('conflict');
    expect($result)->not->toHaveKey('conflictId');
    expect($result['current']['fields']['deletedAt'])->not->toBeNull();
    expect(conflictsFor($g['mark']->id))->toBe([]);
    expect(DB::table('marks')->where('id', $g['mark']->id)->value('score'))->toBe(8);
});

test('A to B to A on a mark: a differing stale edit conflicts, and the original value is the no-op', function () {
    $g = buildGraph('School A', '-cf-roundtrip');
    $g['mark']->update(['score' => 6]);
    $g['mark']->update(['score' => 8]);
    $differing = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);
    $original = pushEntry('marks', $g['mark']->id, 1, ['score' => 8]);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$differing, $original]));

    expect($results[0]['status'])->toBe('conflict');
    expect($results[1])->toBe(['id' => $original['id'], 'status' => 'accepted', 'version' => 3]);
    expect(array_column(conflictsFor($g['mark']->id), 'resolution'))->toBe([null, ['kind' => 'auto']]);
});

test('a version bumped without a log row fails safe to a conflict even when the value is equal', function () {
    $g = buildGraph('School A', '-cf-gap');
    DB::table('marks')->where('id', $g['mark']->id)->update(['version' => 3]);
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 8]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('conflict');
    expect($result['conflictId'])->toBeString();
    $sideA = conflictsFor($g['mark']->id)[0]['side_a'];
    expect($sideA['receivedAt'])->toBeNull();
    expect($sideA['editId'])->toBe(Uuid::uuid5(MarkConflicts::EDIT_ID_NAMESPACE, "marks:{$g['mark']->id}:3")->toString());
});

test('a resent conflict returns the same conflict id with the current cell read afresh', function () {
    $g = buildGraph('School A', '-cf-replay');
    $b = makeColleague($g, 'b-cf-replay@example.com');
    deviceAScores($this, $g, 6);
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);
    $first = postedResults(pushEntries($this, tokenFor($b), [$entry]))[0];

    app(CurrentInstitution::class)->reset();
    $g['mark']->refresh()->update(['score' => 7]);
    app('auth')->forgetGuards();
    $second = postedResults(pushEntries($this, tokenFor($b), [$entry]))[0];

    expect($second['status'])->toBe('conflict');
    expect($second['replayed'])->toBeTrue();
    expect($second['conflictId'])->toBe($first['conflictId']);
    expect($second['current']['version'])->toBe(3);
    expect($second['current']['fields']['score'])->toBe(7);
    expect(conflictsFor($g['mark']->id))->toHaveCount(1);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->count())->toBe(1);
});

test('a further conflicting entry on a cell with an open conflict opens a second two-sided conflict', function () {
    $g = buildGraph('School A', '-cf-second');
    $b = makeColleague($g, 'b-cf-second@example.com');
    $a = deviceAScores($this, $g, 6);
    $one = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);
    $two = pushEntry('marks', $g['mark']->id, 1, ['score' => 5]);

    $results = postedResults(pushEntries($this, tokenFor($b), [$one, $two]));

    expect($results[0]['conflictId'])->not->toBe($results[1]['conflictId']);
    $conflicts = conflictsFor($g['mark']->id);
    expect($conflicts)->toHaveCount(2);
    expect(array_column(array_column($conflicts, 'side_a'), 'editId'))->toBe([$a['id'], $a['id']]);
    expect(array_column($conflicts, 'resolved_at'))->toBe([null, null]);
});

test('a stale mark edit with an invalid value is invalid and writes no conflict', function (array $fields) {
    $g = buildGraph('School A', '-cf-poison');
    $g['mark']->update(['score' => 6]);
    $entry = pushEntry('marks', $g['mark']->id, 1, $fields);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect(conflictsFor($g['mark']->id))->toBe([]);
})->with([
    'a score over the maximum' => [['score' => 999]],
    'an object score' => [['score' => ['a' => 1]]],
    'a fractional score' => [['score' => 7.5]],
    'a markKind outside the enum' => [['markKind' => 'present']],
]);

test('an at that the database would refuse is dropped, and the conflict is recorded with it null', function (string $at) {
    $g = buildGraph('School A', '-cf-at');
    $b = makeColleague($g, 'b-cf-at@example.com');
    deviceAScores($this, $g, 6);
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9], at: $at);

    $result = postedResults(pushEntries($this, tokenFor($b), [$entry]))[0];

    expect($result['status'])->toBe('conflict');
    expect(conflictsFor($g['mark']->id)[0]['side_b']['at'])->toBeNull();
})->with([
    'a NUL byte in the middle' => ["2026-10-07\0T08:00"],
    'over 64 bytes' => [str_repeat('x', 65)],
]);

test('a side naming a user the institution scope hides has no name, and nothing leaks', function () {
    $a = buildGraph('School A', '-cf-who-a');
    $other = buildGraph('School B', '-cf-who-b');
    $b = makeColleague($a, 'b-cf-who@example.com');
    DB::table('marks')->where('id', $a['mark']->id)->update(['last_edited_by' => $other['teacher']->id]);
    $entry = markCreate($a, $a['student'], $a['criterion'], ['markKind' => 'score', 'score' => 3]);

    postedResults(pushEntries($this, tokenFor($b), [$entry]));

    expect(conflictsFor($a['mark']->id)[0]['side_a']['who'])->toBeNull();
});

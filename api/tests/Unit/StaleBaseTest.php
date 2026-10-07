<?php

use App\Sync\Push\RecordHistory;
use App\Sync\Push\StaleBase;
use App\Sync\Push\StaleVerdict;

/* Rule 4 of POST /sync, decided with no database: what a stale entry does, given what
   moved on the record since its base (docs/spec/sync-protocol.md). Pure on purpose, so the
   rule the examiners will probe is a function of its inputs you can read in one place and
   test exhaustively. Wire names are camelCase; the log's columns are snake_case, mapped
   through the table's own column list, never by case conversion. */

const STALE_COLUMNS = ['name' => 'name', 'date' => 'date', 'markKind' => 'mark_kind', 'score' => 'score', 'studentId' => 'student_id'];
const STALE_GROUPS = [['markKind', 'score']];
const STALE_IDENTITY = ['studentId'];

/**
 * @param  list<array<string, mixed>>  $writes  the fields of each write above the base, from version base+1 on
 */
function staleHistory(array $writes, int $base = 1): RecordHistory
{
    $rows = [];

    foreach ($writes as $i => $fields) {
        $rows[] = ['seq' => 100 + $i, 'version' => $base + 1 + $i, 'fields' => $fields, 'receivedAt' => null];
    }

    return new RecordHistory($rows);
}

/**
 * @param  array<string, mixed>  $sent
 * @param  array<string, mixed>  $current
 * @param  list<array<string, mixed>>  $writes
 */
function decideStale(array $sent, array $current, array $writes, bool $trashed = false, bool $entryDeletes = false, ?RecordHistory $history = null, int $base = 1): StaleVerdict
{
    return StaleBase::decide(
        sent: $sent, base: $base, current: $base + count($writes), history: $history ?? staleHistory($writes, $base),
        columns: STALE_COLUMNS, groups: STALE_GROUPS, identity: STALE_IDENTITY,
        currentValues: $current, trashed: $trashed, entryDeletes: $entryDeletes,
    );
}

test('disjoint fields merge', function () {
    expect(decideStale(['date' => '2026-03-02'], ['date' => '2026-02-01'], [['name' => 'Renamed']]))->toBe(StaleVerdict::Merged);
});

test('an overlapping field with a different value conflicts', function () {
    expect(decideStale(['name' => 'Mine'], ['name' => 'Theirs'], [['name' => 'Theirs']]))->toBe(StaleVerdict::ConflictOverlap);
});

test('an overlapping field whose value already matches is the no-op after a collision', function () {
    expect(decideStale(['name' => 'Same'], ['name' => 'Same'], [['name' => 'Same']]))->toBe(StaleVerdict::UnchangedAfterCollision);
});

test('a mix, with one field matching and a disjoint field genuinely different, merges', function () {
    $verdict = decideStale(['name' => 'Same', 'date' => '2026-03-02'], ['name' => 'Same', 'date' => '2026-02-01'], [['name' => 'Same']]);

    expect($verdict)->toBe(StaleVerdict::Merged);
});

test('markKind and score are one group, so a score edit overlaps a markKind change', function () {
    $verdict = decideStale(['score' => 9], ['score' => null], [['mark_kind' => 'absent', 'score' => null]]);

    expect($verdict)->toBe(StaleVerdict::ConflictOverlap);
});

test('a move in the group the entry did not touch still overlaps through the group', function () {
    // The entry sent only score; only markKind moved; same group, so it overlaps and the values differ.
    expect(decideStale(['score' => 9], ['score' => 8], [['mark_kind' => 'score']]))->toBe(StaleVerdict::ConflictOverlap);
});

test('identity fields never overlap, so a create that repeats the triple is not a spurious collision', function () {
    $verdict = decideStale(['studentId' => 's1', 'name' => 'Mine'], ['studentId' => 's1', 'name' => 'Base'], [['student_id' => 's1']]);

    expect($verdict)->toBe(StaleVerdict::Merged);
});

test('a moved column outside the wire map never overlaps', function () {
    expect(decideStale(['name' => 'Mine'], ['name' => 'Base'], [['last_edited_by' => 'u2']]))->toBe(StaleVerdict::Merged);
});

test('a delete above the base conflicts even on disjoint fields', function () {
    expect(decideStale(['date' => '2026-03-02'], ['date' => '2026-02-01'], [['deleted_at' => '2026-10-01T00:00:00Z']]))->toBe(StaleVerdict::ConflictDeleted);
});

test('an entry that itself deletes is not caught by the delete case', function () {
    $verdict = decideStale(['date' => '2026-03-02'], ['date' => '2026-02-01'], [['deleted_at' => '2026-10-01T00:00:00Z']], entryDeletes: true);

    expect($verdict)->not->toBe(StaleVerdict::ConflictDeleted);
});

test('a restore above the base is not a delete', function () {
    expect(decideStale(['date' => '2026-03-02'], ['date' => '2026-02-01'], [['deleted_at' => null]]))->toBe(StaleVerdict::Merged);
});

test('an incomplete history conflicts even when nothing overlaps', function () {
    $gap = new RecordHistory([['seq' => 1, 'version' => 3, 'fields' => ['name' => 'x'], 'receivedAt' => null]]);

    expect(decideStale(['date' => '2026-03-02'], ['date' => '2026-02-01'], [['name' => 'a'], ['name' => 'b']], history: $gap))->toBe(StaleVerdict::ConflictIncomplete);
});

test('the delete case is decided before completeness', function () {
    $gapWithDelete = new RecordHistory([['seq' => 1, 'version' => 3, 'fields' => ['deleted_at' => '2026-10-01T00:00:00Z'], 'receivedAt' => null]]);

    expect(decideStale(['date' => 'x'], ['date' => 'y'], [['name' => 'a'], ['deleted_at' => 'z']], history: $gapWithDelete))->toBe(StaleVerdict::ConflictDeleted);
});

test('a trashed row with no delete above the base is its own verdict', function () {
    expect(decideStale(['date' => '2026-03-02'], ['date' => '2026-02-01'], [['name' => 'a']], trashed: true))->toBe(StaleVerdict::TrashedBase);
});

test('nothing differing and nothing overlapping is unchanged, not merged', function () {
    expect(decideStale(['date' => '2026-02-01'], ['date' => '2026-02-01'], [['name' => 'Renamed']]))->toBe(StaleVerdict::Unchanged);
});

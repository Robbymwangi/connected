<?php

use App\Support\CurrentInstitution;
use App\Sync\Push\RecordHistory;
use Illuminate\Support\Facades\DB;

/* 3.2b: one record's history, read from sync_changes, for rule 4 of POST /sync
   (docs/spec/sync-protocol.md). It answers the questions rule 4 asks of the log: what
   moved above a base, was a delete among it, and is the history whole. Whole means one
   row per version from base+1 to the current version, exactly: a count alone would pass
   a duplicate that hides a gap. */

test('the history above a base is every logged write after it, in version order', function () {
    $g = buildGraph('School A', '-hist-order');
    $g['assessment']->update(['name' => 'Second']);
    $g['assessment']->update(['name' => 'Third']);

    $history = RecordHistory::above($g['assessment'], 1);

    expect($history->versions())->toBe([2, 3]);
    expect($history->at(3)['fields'])->toBe(['name' => 'Third']);
    expect($history->at(1))->toBeNull();
    expect(RecordHistory::above($g['assessment'], 0)->versions())->toBe([1, 2, 3]);
});

test('a field changed and changed back is still in the moved set', function () {
    $g = buildGraph('School A', '-hist-roundtrip');
    $g['assessment']->update(['name' => 'B']);
    $g['assessment']->update(['name' => 'CAT 1']);

    expect(RecordHistory::above($g['assessment'], 1)->movedColumns())->toBe(['name']);
});

test('a create is logged in full, so at base 0 every field it set has moved', function () {
    $g = buildGraph('School A', '-hist-create');

    $moved = RecordHistory::above($g['assessment'], 0)->movedColumns();

    expect($moved)->toContain('class_id', 'subject_id', 'name', 'term', 'year', 'date', 'status', 'created_by');
    expect($moved)->not->toContain('id', 'version', 'institution_id');
});

test('a delete above the base is seen, and a restore alone is not a delete', function () {
    $g = buildGraph('School A', '-hist-delete');
    $g['assessment']->delete();
    $g['assessment']->restore();

    expect(RecordHistory::above($g['assessment'], 1)->deletedAbove())->toBeTrue();
    expect(RecordHistory::above($g['assessment'], 2)->deletedAbove())->toBeFalse();
    expect(RecordHistory::above($g['assessment'], 2)->movedColumns())->toBe(['deleted_at']);
});

test('the history is whole only with exactly one row per version from base+1 to current', function () {
    $g = buildGraph('School A', '-hist-whole');
    $g['assessment']->update(['name' => 'Second']);
    $g['assessment']->update(['name' => 'Third']);
    $id = $g['assessment']->id;

    expect(RecordHistory::above($g['assessment'], 1)->isCompleteBetween(1, 3))->toBeTrue();
    expect(RecordHistory::above($g['assessment'], 3)->isCompleteBetween(3, 3))->toBeTrue();

    // A version bumped with no log row: the log is short.
    DB::table('assessments')->where('id', $id)->update(['version' => 4]);
    expect(RecordHistory::above($g['assessment'], 1)->isCompleteBetween(1, 4))->toBeFalse();
    DB::table('assessments')->where('id', $id)->update(['version' => 3]);

    // A log row removed: a gap.
    $row = DB::table('sync_changes')->where('record_id', $id)->where('version', 2)->first();
    DB::table('sync_changes')->where('seq', $row->seq)->delete();
    expect(RecordHistory::above($g['assessment'], 1)->isCompleteBetween(1, 3))->toBeFalse();

    // A duplicate hiding a gap: the right count, the wrong versions.
    DB::table('sync_changes')->insert([
        'institution_id' => $row->institution_id, 'table' => 'assessments', 'record_id' => $id,
        'version' => 3, 'fields' => json_encode(['name' => 'Third']),
    ]);
    expect(RecordHistory::above($g['assessment'], 1)->isCompleteBetween(1, 3))->toBeFalse();
});

test('another institution\'s rows for the same table and record id are not read', function () {
    $a = buildGraph('School A', '-hist-tenant-a');
    $b = buildGraph('School B', '-hist-tenant-b');
    $a['assessment']->update(['name' => 'Second']);

    DB::table('sync_changes')->insert([
        'institution_id' => $b['institution']->id, 'table' => 'assessments', 'record_id' => $a['assessment']->id,
        'version' => 2, 'fields' => json_encode(['name' => 'Planted']),
    ]);
    app(CurrentInstitution::class)->set($a['institution']->id);

    $history = RecordHistory::above($a['assessment'], 1);

    expect($history->versions())->toBe([2]);
    expect($history->at(2)['fields'])->toBe(['name' => 'Second']);
});

test('receivedAt is UTC ISO 8601 with microseconds, taken in SQL', function () {
    $g = buildGraph('School A', '-hist-time');
    $g['assessment']->update(['name' => 'Second']);

    $stamp = RecordHistory::above($g['assessment'], 1)->at(2)['receivedAt'];

    expect($stamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
});

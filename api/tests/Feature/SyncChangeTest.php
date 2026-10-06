<?php

use App\Models\SyncChange;
use App\Support\CurrentInstitution;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/* Slice 1 of 3.1 (ADR 0010): the sync_changes table and model only. Nothing
   writes to the log yet (slice 2) and nothing reads it over HTTP (slice 3), so
   these tests pin the shape the later slices rely on: the seq cursor is
   assigned by the database, rows are institution-scoped, and the server clock
   fills received_at. */

function syncChangeAttributes(string $institutionId, array $overrides = []): array
{
    return array_merge([
        'institution_id' => $institutionId,
        'table' => 'marks',
        'record_id' => Str::uuid7()->toString(),
        'version' => 1,
        'fields' => ['mark_kind' => 'score', 'score' => 12],
    ], $overrides);
}

test('sync_changes has its data-model columns and none of the synchronisable ones', function () {
    expect(Schema::hasColumns('sync_changes', [
        'seq', 'institution_id', 'table', 'record_id', 'version', 'fields', 'received_at',
    ]))->toBeTrue();

    foreach (['id', 'deleted_at', 'created_at', 'updated_at'] as $column) {
        expect(Schema::hasColumn('sync_changes', $column))->toBeFalse("sync_changes must not have {$column}");
    }
});

test('seq is a database-assigned bigserial that increases with each appended row', function () {
    $g = buildGraph('School A', '-seq');

    $first = SyncChange::create(syncChangeAttributes($g['institution']->id));
    $second = SyncChange::create(syncChangeAttributes($g['institution']->id));

    expect($first->seq)->toBeInt()->toBeGreaterThan(0);
    expect($second->seq)->toBeGreaterThan($first->seq);
    expect(Schema::getColumnType('sync_changes', 'seq'))->toBe('int8');
});

test('fields round-trips as changed fields only, and deleted_at is just another field', function () {
    $g = buildGraph('School A', '-fields');

    $change = SyncChange::create(syncChangeAttributes($g['institution']->id, [
        'fields' => ['deleted_at' => '2026-10-06T08:00:00.000000Z'],
    ]));

    expect(SyncChange::find($change->seq)->fields)->toBe(['deleted_at' => '2026-10-06T08:00:00.000000Z']);
});

test('received_at is filled by the server clock when the writer does not supply it', function () {
    $g = buildGraph('School A', '-clock');

    $before = now()->subSecond();
    $change = SyncChange::create(syncChangeAttributes($g['institution']->id));
    $after = now()->addSecond();

    expect(SyncChange::find($change->seq)->received_at->between($before, $after))->toBeTrue();
});

test('log rows are institution-scoped like every other table', function () {
    $a = buildGraph('School A', '-scope-a');
    $b = buildGraph('School B', '-scope-b');
    $rowA = SyncChange::create(syncChangeAttributes($a['institution']->id));
    $rowB = SyncChange::create(syncChangeAttributes($b['institution']->id));

    app(CurrentInstitution::class)->set($a['institution']->id);

    expect(SyncChange::pluck('institution_id')->unique()->all())->toBe([$a['institution']->id]);
    expect(SyncChange::find($rowA->seq))->not->toBeNull();
    expect(SyncChange::find($rowB->seq))->toBeNull();
});

test('a log row cannot name an institution that does not exist', function () {
    expect(fn () => DB::table('sync_changes')->insert([
        'institution_id' => Str::uuid7()->toString(),
        'table' => 'marks',
        'record_id' => Str::uuid7()->toString(),
        'version' => 1,
        'fields' => json_encode(['score' => 1]),
    ]))->toThrow(QueryException::class);
});

test('a caller cannot choose received_at, the server stamps it', function () {
    $g = buildGraph('School A', '-stamp');

    $change = SyncChange::create(syncChangeAttributes($g['institution']->id, ['received_at' => '2001-01-01T00:00:00Z']));

    expect(SyncChange::find($change->seq)->received_at->year)->toBeGreaterThan(2001);
});

test('sync_changes is indexed for one record\'s history in version order', function () {
    $indexes = collect(Schema::getIndexes('sync_changes'))->pluck('columns');

    expect($indexes->contains(['institution_id', 'table', 'record_id', 'version']))->toBeTrue();
});

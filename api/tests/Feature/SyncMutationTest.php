<?php

use App\Models\Conflict;
use App\Models\SyncMutation;
use App\Support\CurrentInstitution;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/* 3.2a: the record of every decided push entry, backing rule 1 of POST /sync
   (docs/spec/sync-protocol.md; docs/spec/data-model.md, sync_mutations). These
   tests pin the table's shape and the constraints that keep a stored outcome
   coherent: the later slices rely on them rather than re-checking in PHP. */

function mutationAttributes(array $graph, array $overrides = []): array
{
    return array_merge([
        'id' => Str::uuid7()->toString(),
        'institution_id' => $graph['institution']->id,
        'user_id' => $graph['teacher']->id,
        'table' => 'marks',
        'record_id' => $graph['mark']->id,
        'status' => 'accepted',
        'version' => 2,
        'payload_hash' => str_repeat('a', 64),
    ], $overrides);
}

/** Insert a row bypassing the model, so a constraint, not a model hook, is what is tested. */
function insertMutationRaw(array $attributes): void
{
    DB::transaction(fn () => DB::table('sync_mutations')->insert($attributes));
}

test('sync_mutations has its data-model columns and none of the synchronisable ones', function () {
    expect(Schema::hasColumns('sync_mutations', [
        'id', 'institution_id', 'user_id', 'table', 'record_id', 'status', 'version',
        'conflict_id', 'change_seq', 'payload_hash', 'reason', 'at', 'received_at',
    ]))->toBeTrue();

    foreach (['deleted_at', 'created_at', 'updated_at'] as $column) {
        expect(Schema::hasColumn('sync_mutations', $column))->toBeFalse("sync_mutations must not have {$column}");
    }
});

test('an accepted, merged, or conflict row carries a version, and a rejection does not', function () {
    $g = buildGraph('School A', '-mut-version');

    foreach (['accepted', 'merged', 'conflict'] as $status) {
        expect(fn () => insertMutationRaw(mutationAttributes($g, ['status' => $status, 'version' => null])))
            ->toThrow(QueryException::class);
    }

    foreach (['invalid', 'forbidden'] as $status) {
        expect(fn () => insertMutationRaw(mutationAttributes($g, [
            'status' => $status, 'version' => 2, 'reason' => $status === 'invalid' ? 'a reason' : null,
        ])))->toThrow(QueryException::class);
    }
});

test('an invalid row needs a reason, and no other status may have one', function () {
    $g = buildGraph('School A', '-mut-reason');

    expect(fn () => insertMutationRaw(mutationAttributes($g, ['status' => 'invalid', 'version' => null, 'reason' => null])))
        ->toThrow(QueryException::class);

    expect(fn () => insertMutationRaw(mutationAttributes($g, ['status' => 'accepted', 'reason' => 'not allowed here'])))
        ->toThrow(QueryException::class);

    insertMutationRaw(mutationAttributes($g, ['status' => 'invalid', 'version' => null, 'reason' => 'score exceeds criterion max']));
    insertMutationRaw(mutationAttributes($g, ['status' => 'forbidden', 'version' => null]));
    insertMutationRaw(mutationAttributes($g));

    expect(DB::table('sync_mutations')->count())->toBe(3);
});

test('a conflict id is allowed only on a conflict', function () {
    $g = buildGraph('School A', '-mut-conflict');
    $conflict = Conflict::create([
        'institution_id' => $g['institution']->id,
        'mark_id' => $g['mark']->id,
        'base_version' => 1,
        'side_a' => ['editId' => 'a'],
        'side_b' => ['editId' => 'b'],
    ]);

    expect(fn () => insertMutationRaw(mutationAttributes($g, ['status' => 'accepted', 'conflict_id' => $conflict->id])))
        ->toThrow(QueryException::class);

    insertMutationRaw(mutationAttributes($g, ['status' => 'conflict', 'conflict_id' => $conflict->id]));

    expect(DB::table('sync_mutations')->where('conflict_id', $conflict->id)->count())->toBe(1);
});

test('change_seq names at most one mutation, only an accepted or merged one, and only a real change', function () {
    $g = buildGraph('School A', '-mut-seq');
    $seq = DB::table('sync_changes')->where('record_id', $g['mark']->id)->value('seq');

    expect(fn () => insertMutationRaw(mutationAttributes($g, ['status' => 'conflict', 'change_seq' => $seq])))
        ->toThrow(QueryException::class);
    expect(fn () => insertMutationRaw(mutationAttributes($g, ['change_seq' => 999999999])))
        ->toThrow(QueryException::class);

    insertMutationRaw(mutationAttributes($g, ['status' => 'merged', 'change_seq' => $seq]));

    expect(fn () => insertMutationRaw(mutationAttributes($g, ['change_seq' => $seq])))
        ->toThrow(QueryException::class);
    expect(DB::table('sync_mutations')->where('change_seq', $seq)->count())->toBe(1);
});

test('received_at is stamped by the server and cannot be filled', function () {
    $g = buildGraph('School A', '-mut-stamp');

    $mutation = SyncMutation::create(mutationAttributes($g, ['received_at' => '2001-01-01T00:00:00Z']));

    expect(SyncMutation::find($mutation->id)->received_at->year)->toBeGreaterThan(2001);
});

test('the client mutation id is the primary key as given', function () {
    $g = buildGraph('School A', '-mut-key');
    $id = Str::uuid7()->toString();

    SyncMutation::create(mutationAttributes($g, ['id' => $id]));

    expect(SyncMutation::find($id)->getKey())->toBe($id);
    expect(fn () => SyncMutation::create(mutationAttributes($g, ['id' => $id])))->toThrow(QueryException::class);
});

test('rows are append-only: update and delete throw', function () {
    $g = buildGraph('School A', '-mut-append');
    $mutation = SyncMutation::create(mutationAttributes($g));

    expect(fn () => $mutation->update(['reason' => null]))->toThrow(LogicException::class);
    expect(fn () => $mutation->delete())->toThrow(LogicException::class);
    expect(DB::table('sync_mutations')->where('id', $mutation->id)->exists())->toBeTrue();
});

test('the database refuses a bulk update or delete too, not only the model', function () {
    $g = buildGraph('School A', '-mut-bulk');
    $mutation = SyncMutation::create(mutationAttributes($g));

    expect(fn () => DB::transaction(fn () => SyncMutation::query()->whereKey($mutation->id)->update(['status' => 'merged'])))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => SyncMutation::query()->whereKey($mutation->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('sync_mutations')->where('id', $mutation->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');

    expect(DB::table('sync_mutations')->where('id', $mutation->id)->value('status'))->toBe('accepted');
});

test('reads are institution-scoped, but the replay lookup finds another institution\'s id', function () {
    $a = buildGraph('School A', '-mut-scope-a');
    $b = buildGraph('School B', '-mut-scope-b');
    $mutationB = SyncMutation::create(mutationAttributes($b));

    app(CurrentInstitution::class)->set($a['institution']->id);

    expect(SyncMutation::find($mutationB->id))->toBeNull();
    expect(SyncMutation::findForReplay($mutationB->id)?->institution_id)->toBe($b['institution']->id);
});

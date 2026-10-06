<?php

use App\Models\Concerns\Syncable;
use App\Models\Subject;
use App\Models\SyncChange;
use App\Models\User;
use App\Support\SyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/* Slice 2 of 3.1 (ADR 0010 and its 2026-10-06 amendment): every write to a
   synchronisable table appends one row to sync_changes in the same
   transaction, and every transaction that writes one takes the institution's
   advisory lock first, so seq order is commit order. */

function logRowsFor(string $table, string $recordId)
{
    return SyncChange::query()->where('table', $table)->where('record_id', $recordId)->orderBy('seq')->get();
}

test('creating a row appends one change with its initial fields and none of the server-owned ones', function () {
    $g = buildGraph('School A', '-create');

    $subject = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Science']);

    $rows = logRowsFor('subjects', $subject->id);
    expect($rows)->toHaveCount(1);
    expect($rows[0]->institution_id)->toBe($g['institution']->id);
    expect($rows[0]->version)->toBe(0);
    expect($rows[0]->fields)->toBe(['name' => 'Science']);
});

test('updating a row appends only the changed fields at the new version', function () {
    $g = buildGraph('School A', '-update');
    $subject = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Science']);

    $subject->update(['name' => 'Physics']);

    $rows = logRowsFor('subjects', $subject->id);
    expect($rows)->toHaveCount(2);
    expect($rows[1]->version)->toBe(1);
    expect($rows[1]->fields)->toBe(['name' => 'Physics']);
});

test('a soft delete appends deleted_at as the one changed field, and a restore appends it cleared', function () {
    $g = buildGraph('School A', '-delete');

    $g['student']->delete();
    $g['student']->restore();

    $rows = logRowsFor('students', $g['student']->id);
    expect($rows)->toHaveCount(3);
    expect($rows[1]->version)->toBe(1);
    expect(array_keys($rows[1]->fields))->toBe(['deleted_at']);
    expect($rows[1]->fields['deleted_at'])->not->toBeNull();
    expect($rows[2]->version)->toBe(2);
    expect($rows[2]->fields)->toBe(['deleted_at' => null]);
});

test('a no-op save and a bare touch append nothing', function () {
    $g = buildGraph('School A', '-noop');
    $before = SyncChange::count();

    $g['subject']->save();
    $g['subject']->touch();

    expect(SyncChange::count())->toBe($before);
    expect($g['subject']->fresh()->version)->toBe(0);
});

test('a rolled-back transaction leaves neither the row nor its change', function () {
    $g = buildGraph('School A', '-rollback');
    $before = SyncChange::count();

    try {
        DB::transaction(function () use ($g) {
            Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Ghost']);

            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect(Subject::where('name', 'Ghost')->exists())->toBeFalse();
    expect(SyncChange::count())->toBe($before);
});

test('a failed log append aborts the write it belongs to', function () {
    $g = buildGraph('School A', '-fail');
    SyncChange::creating(fn () => throw new RuntimeException('log unavailable'));

    expect(fn () => Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Unlogged']))
        ->toThrow(RuntimeException::class);

    expect(Subject::where('name', 'Unlogged')->exists())->toBeFalse();
});

test('hidden attributes never reach the log, on create or update', function () {
    $g = buildGraph('School A', '-hidden');

    $user = User::create([
        'institution_id' => $g['institution']->id,
        'name' => 'New Teacher',
        'email' => 'new-teacher-hidden@example.com',
        'password' => 'a-hashed-password',
    ]);
    $user->update(['name' => 'Renamed Teacher', 'password' => 'another-hashed-password']);

    $rows = logRowsFor('users', $user->id);
    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row->fields)->not->toHaveKey('password');
    }

    expect($rows[1]->fields)->toBe(['name' => 'Renamed Teacher']);
});

test('seq order is write order within an institution', function () {
    $g = buildGraph('School A', '-order');

    $first = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'One']);
    $second = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Two']);
    $first->update(['name' => 'One, revised']);

    $seqs = SyncChange::query()
        ->whereIn('record_id', [$first->id, $second->id])
        ->orderBy('seq')
        ->pluck('fields', 'seq')
        ->map(fn ($fields) => $fields['name']);

    expect($seqs->values()->all())->toBe(['One', 'Two', 'One, revised']);
});

test('a PUT to a mark appends exactly one change', function () {
    $g = buildGraph('School A', '-put');
    $before = logRowsFor('marks', $g['mark']->id)->count();

    $this->withToken(tokenFor($g['teacher']))
        ->putJson('/api/marks/'.$g['mark']->id, ['mark_kind' => 'score', 'score' => 9])
        ->assertOk();

    $rows = logRowsFor('marks', $g['mark']->id);
    expect($rows)->toHaveCount($before + 1);
    expect($rows->last()->version)->toBe(1);
    expect($rows->last()->fields)->toBe(['score' => 9]);
});

test('the institution lock holds across connections and is per institution', function () {
    $a = buildGraph('School A', '-lock-a');
    config(['database.connections.second' => config('database.connections.'.config('database.default'))]);
    $tryLock = fn (string $institutionId) => DB::connection('second')
        ->selectOne('select pg_try_advisory_xact_lock(hashtextextended(?, 0)) as locked', [$institutionId])->locked;

    SyncLog::transaction($a['institution']->id, function () use ($a, $tryLock) {
        $second = DB::connection('second');
        $second->beginTransaction();

        expect($tryLock($a['institution']->id))->toBeFalse();
        // Every write in this test shares one outer transaction, so school B's
        // own lock is still held from buildGraph; a never-written id is free.
        expect($tryLock(Str::uuid7()->toString()))->toBeTrue();

        $second->rollBack();
    });
});

test('app code opens transactions only through SyncLog', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->reject(fn ($file) => $file->getFilename() === 'SyncLog.php')
        ->filter(fn ($file) => preg_match('/DB::transaction\(|->transaction\(|beginTransaction\(/', $file->getContents()))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('every table with a version column has a Syncable model, so none can skip the log', function () {
    $syncableTables = collect(File::files(app_path('Models')))
        ->map(fn ($file) => 'App\\Models\\'.$file->getBasename('.php'))
        ->filter(fn ($class) => in_array(Syncable::class, class_uses_recursive($class), true))
        ->map(fn ($class) => (new $class)->getTable());

    $versionedTables = collect(Schema::getTables())
        ->pluck('name')
        ->reject(fn ($table) => $table === 'sync_changes') // its version column records another row's version
        ->filter(fn ($table) => Schema::hasColumn($table, 'version'));

    expect($versionedTables->diff($syncableTables)->values()->all())->toBe([]);
});

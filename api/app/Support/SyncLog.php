<?php

namespace App\Support;

use App\Models\SyncChange;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/* The write side of the change log that backs GET /sync (ADR 0010).

   transaction() is the only sanctioned way to open a transaction in app/
   (a test fails on any other). It takes the institution's advisory lock as
   its first statement, before any row is locked or written, so every writer
   acquires locks in the same order: the advisory lock, then rows. That one
   order is what rules out a deadlock between a transaction that locks a row
   first (a mark PUT locks its assessment) and a bare model write that would
   otherwise take the advisory lock first and then want the same row.

   The lock is transaction-scoped and held until commit or rollback, so two
   writers in one institution append and commit one after the other: seq
   order is commit order, and a pull can never advance past a change that has
   yet to commit. It is per institution because a pull is institution-scoped;
   only that institution's rows need a commit order, and seq gaps left by
   other institutions' writes are harmless. A hash collision between two
   institutions would only cost throughput, never correctness. pg_advisory_xact_lock
   is re-entrant within a session, so nested calls are no-ops. */
final class SyncLog
{
    /* Columns the server owns, or that identify the row, never travel as
       changed fields: version is its own log column, and the timestamps and
       institution are server-set. Hidden attributes (a password hash) are
       excluded explicitly here: getDirty() and getAttributes() return hidden
       columns (only toArray() and toJson() honour $hidden), and the log is
       pulled by every device in the school. */
    private const SERVER_OWNED = ['institution_id', 'version', 'created_at', 'updated_at'];

    private static int $depth = 0;

    /* A transaction already opened here is joined, not nested: a model save
       inside a controller's SyncLog::transaction takes the lock again (a no-op
       for the same institution) but adds no savepoint of its own, so a
       failure rolls the whole unit back once, at the outermost level. */
    public static function transaction(string $institutionId, Closure $callback): mixed
    {
        $lockThenRun = function () use ($institutionId, $callback) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$institutionId]);

            return $callback();
        };

        if (self::$depth > 0) {
            return $lockThenRun();
        }

        self::$depth++;

        try {
            return DB::transaction($lockThenRun);
        } finally {
            self::$depth--;
        }
    }

    /**
     * Append one change for the model's write, from the given column names.
     * Nothing is appended when no client-visible field changed.
     *
     * @param  array<int, string>  $columns
     */
    public static function append(Model $model, array $columns): void
    {
        $excluded = [...self::SERVER_OWNED, $model->getKeyName(), ...$model->getHidden()];

        $fields = [];

        foreach (array_diff($columns, $excluded) as $column) {
            $fields[$column] = self::wireValue($model, $column);
        }

        if ($fields === []) {
            return;
        }

        SyncChange::create([
            'institution_id' => $model->institution_id,
            'table' => $model->getTable(),
            'record_id' => $model->getKey(),
            'version' => $model->version,
            'fields' => $fields,
        ]);
    }

    /* Log rows are never rewritten, so a value's format is frozen the moment
       it is logged. A date-only column (a student's dob, an assessment's date)
       is a calendar day, not an instant: logged as a timestamp it would carry
       a midnight-UTC time that a client in another timezone can read as the
       neighbouring day. It is logged as Y-m-d, matching the fixtures; every
       other value keeps the model's own serialisation, ISO 8601 for instants. */
    private static function wireValue(Model $model, string $column): mixed
    {
        $value = $model->getAttribute($column);

        if ($value instanceof CarbonInterface && $model->hasCast($column, ['date'])) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}

<?php

namespace App\Sync;

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Comment;
use App\Models\Conflict;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Result;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectModeration;
use App\Models\SyncChange;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\SyncLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* GET /sync (docs/spec/sync-protocol.md, ADR 0010). Two reads, one response
   shape.

   fromLog() is the ordinary pull: the change log after a seq, in seq order. The
   log is appended to in the writing transaction behind a per-institution lock,
   so seq order is commit order and a pull cannot advance past a change that has
   yet to commit.

   snapshot() is the first pull. It reads the live tables, soft-deleted rows
   included, one change per row. The high-water mark is read before any table is
   scanned, so a change that lands during the scan sits above the mark and
   arrives on the next ordinary pull; a device applies a change only when its
   version is greater than its own, which makes that idempotent. A snapshot
   larger than the limit is paged by a SyncCursor, and only the last page
   returns the plain mark as an integer.

   Pull is institution-wide (the global scopes do that) with one exception:
   a notification is a personal feed, so a user is sent only their own. */
final class SyncPull
{
    /** The synchronisable models, i.e. every model using Syncable; a test keeps this exact. */
    public const MODELS = [
        Assessment::class,
        ClassSubject::class,
        Comment::class,
        Conflict::class,
        Criterion::class,
        Enrolment::class,
        Mark::class,
        Notification::class,
        Report::class,
        Result::class,
        SchoolClass::class,
        Student::class,
        Subject::class,
        SubjectModeration::class,
        TeacherAssignment::class,
        User::class,
    ];

    public function __construct(private readonly string $userId) {}

    /**
     * Table name to model class, in the fixed order a snapshot walks them.
     *
     * @return array<string, class-string<Model>>
     */
    public static function modelsByTable(): array
    {
        $map = [];

        foreach (self::MODELS as $class) {
            $map[(new $class)->getTable()] = $class;
        }

        ksort($map);

        return $map;
    }

    /**
     * @return array{changes: array<int, array<string, mixed>>, cursor: int, more: bool}
     */
    public function fromLog(int $since, int $limit): array
    {
        $rows = SyncChange::query()
            ->where('seq', '>', $since)
            ->where(function ($query) {
                $query->where('table', '!=', 'notifications')
                    ->orWhereIn('record_id', DB::table('notifications')->where('user_id', $this->userId)->select('id'));
            })
            ->orderBy('seq')
            ->limit($limit + 1)
            ->get();

        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'changes' => $rows->map(fn (SyncChange $row) => self::change($row->seq, $row->table, $row->record_id, $row->version, $row->fields))->all(),
            'cursor' => $rows->isEmpty() ? $since : (int) $rows->last()->seq,
            'more' => $more,
        ];
    }

    /**
     * @return array{changes: array<int, array<string, mixed>>, cursor: int|string, more: bool}
     */
    public function snapshot(?SyncCursor $cursor, int $limit): array
    {
        $mark = $cursor?->mark ?? (int) SyncChange::query()->max('seq');
        $models = self::modelsByTable();
        $tables = array_keys($models);
        $start = $cursor === null ? 0 : (int) array_search($cursor->table, $tables, true);

        $changes = [];
        $last = null;

        for ($i = $start; $i < count($tables); $i++) {
            $query = $models[$tables[$i]]::query()
                ->withTrashed()
                ->orderBy('id')
                ->limit($limit - count($changes));

            if ($tables[$i] === 'notifications') {
                $query->where('user_id', $this->userId);
            }

            if ($cursor !== null && $i === $start) {
                $query->where('id', '>', $cursor->after);
            }

            foreach ($query->get() as $model) {
                $changes[] = self::change($mark, $tables[$i], $model->getKey(), $model->version, SyncLog::fieldsFor($model, array_keys($model->getAttributes())));
                $last = [$tables[$i], $model->getKey()];
            }

            if (count($changes) >= $limit) {
                // A page that fills exactly may be followed by an empty final page; that is fine.
                return ['changes' => $changes, 'cursor' => (new SyncCursor($mark, $last[0], $last[1]))->encode(), 'more' => true];
            }
        }

        return ['changes' => $changes, 'cursor' => $mark, 'more' => false];
    }

    /**
     * One record as a pull change, without a seq: the shape POST /sync returns as
     * `current` in a conflict, so a device applies it with the code it uses for a pull row.
     *
     * @return array{table: string, recordId: string, version: int, fields: array<string, mixed>}
     */
    public static function row(Model $model): array
    {
        $change = self::change(0, $model->getTable(), $model->getKey(), $model->version, SyncLog::fieldsFor($model, array_keys($model->getAttributes())));
        unset($change['seq']);

        return $change;
    }

    /* Storage is snake_case, the wire is camelCase. Only the top-level field
       names are mapped: a nested value (a conflict's sides) passes through as
       stored, since its keys are already the wire's. A snapshot row carries
       the mark as its seq, so seq is not unique within a bootstrap and a
       client keys on (table, recordId). */
    private static function change(int $seq, string $table, string $recordId, int $version, array $fields): array
    {
        return [
            'seq' => $seq,
            'table' => $table,
            'recordId' => $recordId,
            'version' => $version,
            'fields' => collect($fields)->mapWithKeys(fn ($value, $name) => [Str::camel($name) => $value])->all(),
        ];
    }
}

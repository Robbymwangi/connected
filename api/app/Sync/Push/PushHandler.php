<?php

namespace App\Sync\Push;

use App\Models\User;
use App\Support\SyncLog;
use Illuminate\Database\Eloquent\Model;

/* What one pushable table declares to POST /sync: which wire names it accepts,
   how a record is found and locked, who may write it, the rules decided before
   the version rules, and how the resulting row is built and validated. A new
   instance is made per entry, since a handler may hold what it locked (a mark
   holds its assessment) between steps.

   A handler never saves its own record. SyncPush does, so the order of checks, the version rules,
   and the recording of the outcome are decided in one place rather than once per
   table. Anything a handler cannot accept it throws as a SyncRejection. */
abstract class PushHandler
{
    public function __construct(protected readonly User $user) {}

    /**
     * The wire names this table accepts, each mapped to its column. Explicit on
     * purpose: names are never converted generically, so a snake_case name or a
     * server-owned column is simply not here.
     *
     * @return array<string, string>
     */
    abstract public function columns(): array;

    /**
     * Wire names that are real but refused for now, each with the reason shown.
     *
     * @return array<string, string>
     */
    public function refused(): array
    {
        return [];
    }

    /**
     * Wire names that move as one fact: touching any member overlaps a move of any member.
     *
     * @return list<list<string>>
     */
    public function mergeGroups(): array
    {
        return [];
    }

    /**
     * Wire names that identify the record and never move; rule 4 leaves them out of every comparison.
     *
     * @return list<string>
     */
    public function identity(): array
    {
        return [];
    }

    /**
     * What the entry itself says, validated, as wire name to value, without touching the
     * record: rule 4 compares it with what moved, and a stale entry holding an invalid value
     * must be answered invalid rather than stored as a conflict side. Identity fields are left out.
     *
     * @return array<string, mixed>
     *
     * @throws SyncRejection
     */
    abstract public function incoming(PushEntry $entry, Model $record): array;

    /** The record's current value for a wire name, in the same wire format as incoming(). */
    public function currentValue(Model $record, string $wire): mixed
    {
        $column = $this->columns()[$wire];

        return SyncLog::fieldsFor($record, [$column])[$column] ?? null;
    }

    /** The record, soft-deleted rows included, scoped to the institution; locked when asked. */
    abstract public function find(string $recordId, bool $lock = false): ?Model;

    /** Find and lock everything the entry depends on, in a fixed order, and return the record. */
    public function lockRecord(PushEntry $entry): ?Model
    {
        return $this->find($entry->recordId, true);
    }

    /** Whether this user may create (null) or update (the record) here. */
    abstract public function authorize(?Model $record): bool;

    /**
     * A command is validated against the record's stored state and never merged by rule 4: a base behind the
     * record is answered with the record itself, and the state checks run only at an equal version.
     */
    public function isCommand(): bool
    {
        return false;
    }

    /** Called inside the entry's transaction after the record is saved, for what must happen only once. */
    public function written(PushEntry $entry, Model $saved): void {}

    /** Rules decided before the version rules; throws SyncRejection. */
    public function check(PushEntry $entry, ?Model $record): void {}

    /**
     * Build the unsaved row that would result and validate it, never the payload
     * alone: a patch is checked against the row it patches.
     *
     * @throws SyncRejection
     */
    abstract public function resulting(PushEntry $entry, ?Model $record): Model;
}

<?php

namespace App\Sync\Push;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/* What one pushable table declares to POST /sync: which wire names it accepts,
   how a record is found and locked, who may write it, the rules decided before
   the version rules, and how the resulting row is built and validated. A new
   instance is made per entry, since a handler may hold what it locked (a mark
   holds its assessment) between steps.

   A handler never saves. SyncPush does, so the order of checks, the version rules,
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

    /** The record, soft-deleted rows included, scoped to the institution; locked when asked. */
    abstract public function find(string $recordId, bool $lock = false): ?Model;

    /** Find and lock everything the entry depends on, in a fixed order, and return the record. */
    public function lockRecord(PushEntry $entry): ?Model
    {
        return $this->find($entry->recordId, true);
    }

    /** Whether this user may create (null) or update (the record) here. */
    abstract public function authorize(?Model $record): bool;

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

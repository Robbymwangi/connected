<?php

namespace App\Sync\Push;

use App\Models\SyncChange;
use Illuminate\Database\Eloquent\Model;

/* One record's history above a base version, as rule 4 of POST /sync asks it
   (docs/spec/sync-protocol.md): which fields moved, was the record deleted, and is the
   history whole enough to reason from. It is the only rule-4 class that reads the
   database; the decision itself (StaleBase) is pure and works from what this returns.

   The source is sync_changes, not updated_at: the log records, per write, the fields whose
   values changed (a create in full, a restore as deleted_at: null), which "what changed
   since version 6" has no answer for from a timestamp. Read inside the entry's
   transaction, after the institution's advisory lock, so no write can land in the middle
   of it: every append in the institution takes the same lock. The read is institution-
   scoped like every other, through SyncChange's global scope.

   receivedAt is formatted in SQL as UTC ISO 8601 with microseconds, so a conflict side
   carries a fixed, sortable string and nothing parses a timestamp in PHP. */
final class RecordHistory
{
    /**
     * @param  list<array{seq: int, version: int, fields: array<string, mixed>, receivedAt: string|null}>  $rows  in version order
     */
    public function __construct(private readonly array $rows) {}

    public static function above(Model $record, int $base): self
    {
        $rows = SyncChange::query()
            ->select(['seq', 'version', 'fields'])
            ->selectRaw(<<<'SQL'
                to_char(received_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') as received_at_utc
                SQL)
            ->where('table', $record->getTable())
            ->where('record_id', $record->getKey())
            ->where('version', '>', $base)
            ->orderBy('version')
            ->orderBy('seq')
            ->get();

        return new self($rows->map(fn (SyncChange $row) => [
            'seq' => (int) $row->seq,
            'version' => (int) $row->version,
            'fields' => $row->fields ?? [],
            'receivedAt' => $row->received_at_utc,
        ])->all());
    }

    /**
     * The versions present, in order.
     *
     * @return list<int>
     */
    public function versions(): array
    {
        return array_column($this->rows, 'version');
    }

    /* Whole means exactly one row for each version from base+1 to the current version. Not
       a count: a duplicate row beside a missing one has the right count and the wrong
       history. When it is not whole (a write the log missed, a row removed, a duplicate),
       rule 4 refuses to merge from it and the entry conflicts, the safe answer. */
    public function isCompleteBetween(int $base, int $current): bool
    {
        if ($current <= $base) {
            return $this->rows === [];
        }

        return $this->versions() === range($base + 1, $current);
    }

    /* A log row above the base that sets deleted_at to something. A restore logs null and is
       not a delete; the key alone would not tell them apart. */
    public function deletedAbove(): bool
    {
        foreach ($this->rows as $row) {
            if (array_key_exists('deleted_at', $row['fields']) && $row['fields']['deleted_at'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every column that moved in any write above the base. Per write, not net: a field changed
     * from A to B and back to A is in the set, because another device may have read it at B.
     *
     * @return list<string>
     */
    public function movedColumns(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (array $row) => array_keys($row['fields']),
            $this->rows,
        ))));
    }

    /**
     * The row for one version, or null where the history has none.
     *
     * @return array{seq: int, version: int, fields: array<string, mixed>, receivedAt: string|null}|null
     */
    public function at(int $version): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['version'] === $version) {
                return $row;
            }
        }

        return null;
    }
}

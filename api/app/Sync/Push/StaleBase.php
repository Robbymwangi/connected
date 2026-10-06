<?php

namespace App\Sync\Push;

/* Rule 4 of POST /sync (docs/spec/sync-protocol.md): an entry whose base version is
   behind the record's. Pure on purpose: it takes what the entry sent, what the record holds
   now, and what moved on the record since the entry's base, and returns a verdict. No
   database, no models, so the rule examiners will probe is a function of its inputs that can
   be read in one place and tested exhaustively.

   Conflicts are detected by stale base version and then by what moved, never by comparing
   timestamps: clock skew across teacher devices makes wall-clock comparison unsound
   (AGENTS.md). The set of what moved comes from the change log, per write, so a field
   changed from A to B and back to A is in it.

   The steps, in this order:
     1. A delete above the base is a conflict outright, whatever fields the entry touches:
        an edit that does not know its target has vanished must surface, not merge because
        its field names happen not to be deletedAt. Decided first, before anything could
        trip over a deleted row.
     2. A history that is not whole conflicts: nothing safe can be merged from a log with a
        hole in it.
     3. A deleted record with nothing above the base that deleted it is not specified, and is
        refused.
     4. What the entry touches (minus identity fields, which never move) is compared with
        what moved, through merge groups: a group moves as one fact, so touching either member
        overlaps a move of any member.
     5. An overlapping field whose value differs is a conflict. If everything the entry
        touches already holds the value it sent, it is the no-op, with or without a collision.
        Otherwise it is a merge, applying every field the entry sent. */
final class StaleBase
{
    /**
     * @param  array<string, mixed>  $sent  wire name to the entry's validated value, for every field it touches
     * @param  array<string, string>  $columns  wire name to column, the table's allowlist
     * @param  list<list<string>>  $groups  wire names that move as one fact
     * @param  list<string>  $identity  wire names that never move
     * @param  array<string, mixed>  $currentValues  wire name to the record's current value, for the sent names
     */
    public static function decide(
        array $sent,
        int $base,
        int $current,
        RecordHistory $history,
        array $columns,
        array $groups,
        array $identity,
        array $currentValues,
        bool $trashed,
        bool $entryDeletes,
    ): StaleVerdict {
        if ($history->deletedAbove() && ! $entryDeletes) {
            return StaleVerdict::ConflictDeleted;
        }

        if (! $history->isCompleteBetween($base, $current)) {
            return StaleVerdict::ConflictIncomplete;
        }

        if ($trashed) {
            return StaleVerdict::TrashedBase;
        }

        $touched = array_values(array_diff(array_keys($sent), $identity));

        // Only columns the table maps to a wire name can overlap; last_edited_by, status and the like never do.
        $columnToWire = array_flip($columns);
        $moved = array_values(array_diff(
            array_filter(array_map(fn (string $column) => $columnToWire[$column] ?? null, $history->movedColumns())),
            $identity,
        ));

        $groupOf = function (string $name) use ($groups): array {
            foreach ($groups as $group) {
                if (in_array($name, $group, true)) {
                    return $group;
                }
            }

            return [$name];
        };

        $overlap = array_values(array_filter($touched, fn (string $name) => array_intersect($groupOf($name), $moved) !== []));
        $differing = array_values(array_filter($touched, fn (string $name) => $sent[$name] !== $currentValues[$name]));

        if (array_intersect($differing, $overlap) !== []) {
            return StaleVerdict::ConflictOverlap;
        }

        if ($differing === []) {
            return $overlap === [] ? StaleVerdict::Unchanged : StaleVerdict::UnchangedAfterCollision;
        }

        return StaleVerdict::Merged;
    }
}

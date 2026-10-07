<?php

namespace App\Sync\Push;

use App\Models\Conflict;
use App\Models\Mark;
use App\Models\SyncMutation;
use App\Models\User;
use App\Sync\ServerClock;
use App\Sync\ServerNotifications;
use Ramsey\Uuid\Uuid;

/* The conflicts record for a mark, written when two teachers' edits to one cell meet
   (ADR 0002 and its 2026-10-06 amendment: a conflict record is a mark conflict; docs/spec/
   sync-protocol.md, rule 4). Two sides, side by side, never "mine" and "theirs":

     side A  the write that produced the record's current version, found by version order and
             the change it wrote, never by anyone's `at`;
     side B  the incoming entry.

   Each side is flat: editId, userId, who, markKind, score, at, receivedAt. `at` is the
   device's own claim, shown as information only. `receivedAt` is the server's own clock for
   when it saw that write, which is what an auditor checks `at` against.

   Side A's producer is found by link: sync_mutations.change_seq names the change-log row a
   mutation wrote. A write that is not a sync mutation (a resolution's write, a server write,
   data from before the link existed, or a hole in the log) has no mutation, so its editId is a
   UUIDv5 of the cell and the version, which exists even where the log row is missing, and
   its user is whoever the cell credits. `who` is looked up through the institution scope,
   so a user the scope hides has no name and nothing leaks.

   An auto conflict (both teachers entered the same value) is recorded already resolved, with
   resolved_at set: finalize refuses while any conflict is unresolved. */
final class MarkConflicts
{
    /** Fixed once and never changed: it is what makes a derived editId reproducible. */
    public const EDIT_ID_NAMESPACE = '2987a563-31f5-43ef-a13d-e780cd210fef';

    public function __construct(private readonly User $user) {}

    /**
     * @param  array<string, mixed>  $sideB  from side()
     */
    public function raise(Mark $record, RecordHistory $history, int $baseVersion, array $sideB, bool $auto): Conflict
    {
        $conflict = Conflict::create([
            'institution_id' => $this->user->institution_id,
            'mark_id' => $record->id,
            'base_version' => $baseVersion,
            // The version side A produced: what a resolution measures "has the mark moved since" against.
            'mark_version' => $record->version,
            'side_a' => $this->producerSide($record, $history),
            'side_b' => $sideB,
            'proposals' => [],
            'referral' => null,
            'resolution' => $auto ? ['kind' => 'auto'] : null,
            'resolved_at' => $auto ? now() : null,
        ]);

        // An auto conflict has nothing to settle, so nobody is told; an open one tells both parties.
        if (! $auto) {
            (new ServerNotifications)->conflictRaised($conflict, $record->assessment_id);
        }

        return $conflict;
    }

    /* Side B: the write that is not in the record. For a rule-4 conflict it is the entry (its mutation id,
       the token's user); for a follow-up raised by a resolution it is the chosen value (the command's mutation
       id, the credited user). receivedAt is one clock reading taken here: nothing is logged for side B, and the
       mutation's own row cannot come first, because it references the conflict. It precedes the mutation's
       received_at by microseconds; both are the server's clock. `who` is null where the scope hides the user.

       @param  array<string, mixed>  $cell  markKind and score
       @return array<string, mixed>
     */
    public function side(string $editId, string $userId, ?string $who, array $cell, ?string $at): array
    {
        return [
            'editId' => $editId,
            'userId' => $userId,
            'who' => $who,
            'markKind' => $cell['markKind'],
            'score' => $cell['score'],
            'at' => $at,
            'receivedAt' => ServerClock::now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function producerSide(Mark $record, RecordHistory $history): array
    {
        $version = $record->version;
        $row = $history->at($version);
        $mutation = $row === null ? null : SyncMutation::query()->where('change_seq', $row['seq'])->first();

        if ($mutation !== null) {
            $editId = $mutation->id;
            $userId = $mutation->user_id;
            $at = $mutation->at;
        } else {
            $editId = Uuid::uuid5(self::EDIT_ID_NAMESPACE, "marks:{$record->id}:{$version}")->toString();
            $userId = $record->last_edited_by;
            $at = null;
        }

        return [
            'editId' => $editId,
            'userId' => $userId,
            'who' => User::withTrashed()->find($userId)?->name,
            'markKind' => $record->mark_kind,
            'score' => $record->score,
            'at' => $at,
            'receivedAt' => $row['receivedAt'] ?? null,
        ];
    }
}

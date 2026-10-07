<?php

namespace App\Sync\Push;

use App\Models\Conflict;
use App\Models\Mark;
use App\Models\SyncMutation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
   mutation wrote. A write that is not a sync mutation (a REST write, a server write, data
   from before the link existed, or a hole in the log) has no mutation, so its editId is a
   UUIDv5 of the cell and the version, which exists even where the log row is missing, and
   its user is whoever the cell credits. `who` is looked up through the institution scope,
   so a user the scope hides has no name and nothing leaks.

   Side B's receivedAt is one clock reading taken here: nothing is logged for side B, and the
   mutation's own row cannot come first, because it references the conflict. It precedes the
   mutation's received_at by microseconds; both are the server's clock.

   An auto conflict (both teachers entered the same value) is recorded already resolved, with
   resolved_at set: finalize refuses while any conflict is unresolved. */
final class MarkConflicts
{
    /** Fixed once and never changed: it is what makes a derived editId reproducible. */
    public const EDIT_ID_NAMESPACE = '2987a563-31f5-43ef-a13d-e780cd210fef';

    public function __construct(private readonly User $user) {}

    /**
     * @param  array<string, mixed>  $incoming  the entry's own validated cell: markKind and score
     */
    public function raise(PushEntry $entry, Mark $record, RecordHistory $history, array $incoming, bool $auto): Conflict
    {
        return Conflict::create([
            'institution_id' => $this->user->institution_id,
            'mark_id' => $record->id,
            'base_version' => $entry->baseVersion,
            'side_a' => $this->producerSide($record, $history),
            'side_b' => $this->incomingSide($entry, $incoming),
            'proposals' => [],
            'referral' => null,
            'resolution' => $auto ? ['kind' => 'auto'] : null,
            'resolved_at' => $auto ? now() : null,
        ]);
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

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function incomingSide(PushEntry $entry, array $incoming): array
    {
        return [
            'editId' => $entry->id,
            'userId' => $this->user->id,
            'who' => $this->user->name,
            'markKind' => $incoming['markKind'],
            'score' => $incoming['score'],
            'at' => $entry->at,
            'receivedAt' => DB::scalar(<<<'SQL'
                select to_char(clock_timestamp() at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')
                SQL),
        ];
    }
}

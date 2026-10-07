<?php

namespace App\Sync\Push;

use App\Models\Assessment;
use App\Models\Conflict;
use App\Models\Criterion;
use App\Models\Mark;
use App\Models\User;
use LogicException;

/* What a resolution does to the mark (docs/spec/sync-protocol.md, "A resolution writes the mark"). The chosen value is
   a write made against the mark's version when the conflict was raised (conflicts.mark_version), so the ordinary rule 4
   decides it, through the same StaleBase every other stale write goes through:

     nothing it touches moved       apply it, credited as below;
     the cell already holds it      write nothing: no version, no credit change, no log row;
     anything else (an overlap, a deleted-then-restored mark, a history with a hole)
                                    leave the mark, and raise a follow-up conflict between the producer of the current
                                    version and the chosen value. The resolution itself is always recorded, so every
                                    conflict can close and finalize, which refuses while one is open, stays possible.

   The plan is decided in plan(), before anything is saved, so every refusal is a rejection that rolls the entry back;
   it is carried out in execute(), after the conflict itself is saved, in the same transaction. Nothing the plan reads
   is taken on trust: the chosen value is validated as a mark cell before any branch (a tampered stored side must be an
   answer, never reach the table's check constraint as a 5xx, and never become a follow-up's side), and so is the user
   it is credited to.

   Credit follows the value: a side choice credits that side's user, a corrected value credits the user acting. */
final class MarkSettlement
{
    public const APPLY = 'apply';

    public const NOTHING = 'nothing';

    public const FOLLOW_UP = 'follow-up';

    /**
     * @param  array{markKind: string, score: int|null}  $cell
     */
    private function __construct(
        public readonly string $action,
        public readonly array $cell,
        public readonly User $credited,
        public readonly RecordHistory $history,
    ) {}

    /**
     * @param  array<string, mixed>  $choice  validated and rebuilt (ConflictPushHandler::validatedChoice)
     *
     * @throws SyncRejection
     */
    public static function plan(User $actor, Assessment $assessment, Mark $mark, Conflict $conflict, array $choice): self
    {
        if ($assessment->trashed()) {
            throw SyncRejection::invalid('the assessment has been deleted');
        }

        if (in_array($assessment->status, ['finalized', 'reports-generated'], true)) {
            throw SyncRejection::invalid('the assessment is finalized; unlock it first');
        }

        if ($mark->trashed()) {
            throw SyncRejection::invalid('the conflict\'s mark has been deleted');
        }

        [$cell, $creditedId] = self::chosen($actor, $conflict, $choice);

        $criterion = Criterion::withTrashed()->find($mark->criterion_id)
            ?? throw SyncRejection::invalid('criterionId does not resolve');

        self::assertCell($cell, $criterion);

        $credited = $creditedId === $actor->id ? $actor : User::withTrashed()->find($creditedId);

        if ($credited === null) {
            throw SyncRejection::invalid('the user the chosen value is credited to does not resolve');
        }

        $history = RecordHistory::above($mark, $conflict->mark_version);

        return new self(self::decide($actor, $cell, $mark, $conflict, $history), $cell, $credited, $history);
    }

    /* Rule 4, with the mark's own columns and merge groups. A recorded mark_version above the mark's own is corrupt
       data; failing safe means a follow-up, never an apply on a history that cannot be read. */
    private static function decide(User $actor, array $cell, Mark $mark, Conflict $conflict, RecordHistory $history): string
    {
        if ($conflict->mark_version > $mark->version) {
            return self::FOLLOW_UP;
        }

        $marks = new MarkPushHandler($actor);
        $currentValues = [];

        foreach (array_keys($cell) as $wire) {
            $currentValues[$wire] = $marks->currentValue($mark, $wire);
        }

        $verdict = StaleBase::decide(
            sent: $cell,
            base: $conflict->mark_version,
            current: $mark->version,
            history: $history,
            columns: $marks->columns(),
            groups: $marks->mergeGroups(),
            identity: $marks->identity(),
            currentValues: $currentValues,
            trashed: false,
            entryDeletes: false,
        );

        return match ($verdict) {
            StaleVerdict::Merged => self::APPLY,
            StaleVerdict::Unchanged, StaleVerdict::UnchangedAfterCollision => self::NOTHING,
            StaleVerdict::ConflictOverlap, StaleVerdict::ConflictIncomplete, StaleVerdict::ConflictDeleted => self::FOLLOW_UP,
            // The mark was checked not to be deleted above; reaching here is a defect, not an answer.
            StaleVerdict::TrashedBase => throw new LogicException('A deleted mark reached rule 4 after it was refused.'),
        };
    }

    /**
     * Carry the plan out: write the mark, or raise the follow-up. Called after the conflict is saved.
     */
    public function execute(PushEntry $entry, Mark $mark, Conflict $conflict, User $actor): void
    {
        if ($this->action === self::APPLY) {
            $mark->fill(['mark_kind' => $this->cell['markKind'], 'score' => $this->cell['score']]);
            $mark->last_edited_by = $this->credited->id;
            $mark->save();

            return;
        }

        if ($this->action === self::FOLLOW_UP) {
            $conflicts = new MarkConflicts($actor);
            $sideB = $conflicts->side($entry->id, $this->credited->id, $this->credited->name, $this->cell, $entry->at);

            $conflicts->raise($mark, $this->history, $conflict->mark_version, $sideB, auto: false);
        }
    }

    /**
     * The cell the choice names and who it is credited to. A side's cell is read from the stored side, which is why
     * it is validated afterwards: the store is ours, but a defect or a hand edit there must not become a 5xx.
     *
     * @param  array<string, mixed>  $choice
     * @return array{0: array{markKind: string, score: int|null}, 1: string}
     */
    private static function chosen(User $actor, Conflict $conflict, array $choice): array
    {
        if ($choice['kind'] === 'side') {
            $side = $choice['editId'] === ($conflict->side_a['editId'] ?? null) ? $conflict->side_a : $conflict->side_b;

            return [['markKind' => $side['markKind'] ?? null, 'score' => $side['score'] ?? null], (string) ($side['userId'] ?? '')];
        }

        $mark = $choice['mark'];

        return [
            $mark['kind'] === 'absent' ? ['markKind' => 'absent', 'score' => null] : ['markKind' => 'score', 'score' => $mark['value']],
            (string) $actor->id,
        ];
    }

    /**
     * @param  array{markKind: mixed, score: mixed}  $cell
     */
    private static function assertCell(array $cell, Criterion $criterion): void
    {
        if (! in_array($cell['markKind'], ['empty', 'score', 'absent'], true)) {
            throw SyncRejection::invalid('the chosen mark kind is not valid');
        }

        if ($cell['markKind'] !== 'score') {
            if ($cell['score'] !== null) {
                throw SyncRejection::invalid('the chosen mark has a score it cannot have');
            }

            return;
        }

        if (! is_int($cell['score']) || $cell['score'] < 0) {
            throw SyncRejection::invalid('the chosen mark has no valid score');
        }

        if ($cell['score'] > $criterion->max_score) {
            throw SyncRejection::invalid("the chosen score is above the criterion maximum of {$criterion->max_score}");
        }
    }
}

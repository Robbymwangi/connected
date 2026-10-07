<?php

namespace App\Sync\Push;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/* Marks over POST /sync. Grading is unrestricted by who (docs/spec/access-model.md),
   so authorization is always yes; every other rule is here: the deterministic id,
   references that resolve inside the institution, an enrolled student, a criterion
   of the assessment's subject, the criterion's maximum, the finalized lock, and
   markKind and score held together as one cell (the table's check constraint is the
   backstop, but a value reaching it would be a 5xx, not an answer).

   A mark's id is the UUIDv5 of its (assessment, student, criterion) triple, so the
   triple is identity: an update may repeat it but never change it. Repeating it
   matters, because two devices creating one cell both send it at base 0 against a
   known id, and that must reach the version rules as a conflict, not be refused.

   Rows are locked assessment first, then the mark, the order finalize and the REST
   path use, so this never takes them the other way round. */
final class MarkPushHandler extends PushHandler
{
    private const IDENTITY = ['assessmentId' => 'assessment_id', 'studentId' => 'student_id', 'criterionId' => 'criterion_id'];

    private const FINALIZED = ['finalized', 'reports-generated'];

    private ?Assessment $assessment = null;

    public function columns(): array
    {
        return [...self::IDENTITY, 'markKind' => 'mark_kind', 'score' => 'score'];
    }

    /* The cell is one fact: a score edit overlaps a change of kind, and the reverse. */
    public function mergeGroups(): array
    {
        return [['markKind', 'score']];
    }

    public function identity(): array
    {
        return array_keys(self::IDENTITY);
    }

    /* The cell the entry itself says. A device sends changed fields only, so a score edit
       is {score: 14} with no markKind; the kind it means is score, not whatever the cell
       holds now, or a cell since made absent would answer invalid instead of raising the
       genuine conflict. Validated through cell(), without filling the record. */
    public function incoming(PushEntry $entry, Model $record): array
    {
        $fields = $entry->fields;

        $criterion = Criterion::withTrashed()->find($record->criterion_id)
            ?? throw SyncRejection::invalid('criterionId does not resolve');

        $implied = $fields['markKind'] ?? (is_int($fields['score'] ?? null) ? 'score' : $record->mark_kind);

        [$kind, $score] = $this->cell(['markKind' => $implied] + $fields, $record, $criterion);

        return ['markKind' => $kind, 'score' => $score];
    }

    public function refused(): array
    {
        return ['deletedAt' => 'a mark cannot be deleted; clear it with markKind empty'];
    }

    public function find(string $recordId, bool $lock = false): ?Model
    {
        $query = Mark::withTrashed();

        return ($lock ? $query->lockForUpdate() : $query)->find($recordId);
    }

    public function lockRecord(PushEntry $entry): ?Model
    {
        // assessment_id never changes, so reading it unlocked to learn which assessment to lock is safe.
        $assessmentId = Mark::withTrashed()->find($entry->recordId)?->assessment_id
            ?? (is_string($entry->fields['assessmentId'] ?? null) && Str::isUuid($entry->fields['assessmentId']) ? $entry->fields['assessmentId'] : null);

        $this->assessment = $assessmentId === null ? null : Assessment::lockForUpdate()->find($assessmentId);

        return $this->find($entry->recordId, true);
    }

    public function authorize(?Model $record): bool
    {
        return Gate::forUser($this->user)->allows($record === null ? 'create' : 'update', $record ?? Mark::class);
    }

    public function check(PushEntry $entry, ?Model $record): void
    {
        // Decided before the mark's own version is considered: an accepted write here would
        // silently defeat the lock finalize exists to be (docs/spec/workflow.md).
        if ($this->assessment !== null && in_array($this->assessment->status, self::FINALIZED, true)) {
            throw SyncRejection::invalid('Marks cannot be changed while the assessment is finalized. Unlock it first.');
        }

        if ($record === null) {
            return;
        }

        foreach (self::IDENTITY as $name => $column) {
            if (array_key_exists($name, $entry->fields) && $entry->fields[$name] !== (string) $record->{$column}) {
                throw SyncRejection::invalid("{$name} is immutable");
            }
        }
    }

    public function resulting(PushEntry $entry, ?Model $record): Model
    {
        return $record === null ? $this->creation($entry) : $this->revision($entry, $record);
    }

    private function creation(PushEntry $entry): Mark
    {
        $fields = $entry->fields;

        foreach ([...array_keys(self::IDENTITY), 'markKind'] as $name) {
            if (! array_key_exists($name, $fields)) {
                throw SyncRejection::invalid("{$name} is required");
            }
        }

        foreach (array_keys(self::IDENTITY) as $name) {
            if (! is_string($fields[$name]) || ! Str::isUuid($fields[$name])) {
                throw SyncRejection::invalid("{$name} must be a UUID");
            }
        }

        // Checked before anything is saved: the model's own creating hook throws on a mismatch, and a throw is a 5xx.
        if ($entry->recordId !== self::cellId($fields['assessmentId'], $fields['studentId'], $fields['criterionId'])) {
            throw SyncRejection::invalid('recordId is not the deterministic id of (assessmentId, studentId, criterionId)');
        }

        $assessment = $this->assessment ?? throw SyncRejection::invalid('assessmentId does not resolve');
        $student = Student::find($fields['studentId']) ?? throw SyncRejection::invalid('studentId does not resolve');
        $criterion = Criterion::find($fields['criterionId']) ?? throw SyncRejection::invalid('criterionId does not resolve');

        if ($criterion->subject_id !== $assessment->subject_id) {
            throw SyncRejection::invalid('The criterion must belong to the assessment subject.');
        }

        $enrolled = Enrolment::query()
            ->where('student_id', $student->id)
            ->where('class_id', $assessment->class_id)
            ->where('year', $assessment->year)
            ->exists();

        if (! $enrolled) {
            throw SyncRejection::invalid('The student must be enrolled in the assessment class and year.');
        }

        [$kind, $score] = $this->cell($fields, null, $criterion);

        return new Mark([
            'id' => $entry->recordId,
            'institution_id' => $this->user->institution_id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'criterion_id' => $criterion->id,
            'mark_kind' => $kind,
            'score' => $score,
            'last_edited_by' => $this->user->id,
        ]);
    }

    /* A patch is checked against the cell it patches, not on its own: {score: 14}
       means nothing without the current markKind and the criterion's maximum. Moving
       to absent or empty clears the score, as the grid does. last_edited_by is the
       token's user, never the payload's; it is the one field a patch sets that the
       device did not send. */
    private function revision(PushEntry $entry, Mark $record): Mark
    {
        if ($record->trashed()) {
            throw SyncRejection::invalid('the mark has been deleted');
        }

        // The foreign key does not check the institution, so a mark can point at a criterion the
        // scope hides; that is an answer, not a 5xx.
        $criterion = Criterion::withTrashed()->find($record->criterion_id)
            ?? throw SyncRejection::invalid('criterionId does not resolve');

        [$kind, $score] = $this->cell($entry->fields, $record, $criterion);

        $record->fill(['mark_kind' => $kind, 'score' => $score, 'last_edited_by' => $this->user->id]);

        return $record;
    }

    /**
     * The markKind and score that result from the fields over the current cell.
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: string, 1: int|null}
     */
    private function cell(array $fields, ?Mark $current, Criterion $criterion): array
    {
        $kind = $fields['markKind'] ?? $current?->mark_kind;

        if (! in_array($kind, ['empty', 'score', 'absent'], true)) {
            throw SyncRejection::invalid('markKind must be one of empty, score, absent');
        }

        $score = array_key_exists('score', $fields)
            ? $fields['score']
            : ($kind === 'score' ? $current?->score : null);

        if ($kind !== 'score') {
            if ($score !== null) {
                throw SyncRejection::invalid('score is only allowed when markKind is score');
            }

            return [$kind, null];
        }

        if ($score === null) {
            throw SyncRejection::invalid('score is required when markKind is score');
        }

        if (! is_int($score) || $score < 0) {
            throw SyncRejection::invalid('score must be a whole number from 0 to the criterion maximum');
        }

        if ($score > $criterion->max_score) {
            throw SyncRejection::invalid('The score may not be greater than the criterion maximum.');
        }

        return [$kind, $score];
    }

    /* The id a device computes for a cell: the model's own formula, called with strings
       before any Mark exists. */
    private static function cellId(string $assessmentId, string $studentId, string $criterionId): string
    {
        return (new Mark([
            'assessment_id' => $assessmentId,
            'student_id' => $studentId,
            'criterion_id' => $criterionId,
        ]))->newUniqueId();
    }
}

<?php

namespace App\Sync\Push;

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/* Assessments over POST /sync. Creation is unrestricted by who, as in
   docs/spec/access-model.md; an update is scoped to its creator or an assigned
   teacher, the policy's rule. deletedAt is refused, since no assessment is deleted from a
   device, and status is accepted only as a finalize entry (see check()). Every type and range is checked here in PHP, before any query or save,
   because a malformed value reaching Postgres would be a 5xx, not an answer, and a
   5xx on one entry stalls every entry behind it in the outbox on each resend. */
final class AssessmentPushHandler extends PushHandler
{
    /** What a create must carry; the finalize names below are never part of one. */
    private const CREATE_FIELDS = ['classId', 'subjectId', 'name', 'term', 'year', 'date'];

    /** The three names a finalize entry may carry. */
    private const FINALIZE_NAMES = ['status', 'finalizedBy', 'finalizedAt'];

    private const FINALIZED = ['finalized', 'reports-generated'];

    public function columns(): array
    {
        return [
            'classId' => 'class_id',
            'subjectId' => 'subject_id',
            'name' => 'name',
            'term' => 'term',
            'year' => 'year',
            'date' => 'date',
            'status' => 'status',
            'finalizedBy' => 'finalized_by',
            'finalizedAt' => 'finalized_at',
        ];
    }

    /* An offered class and subject is one fact: a merged pair neither device chose should
       surface as a conflict, not be assembled from two edits. */
    public function mergeGroups(): array
    {
        return [['classId', 'subjectId']];
    }

    /* A finalize entry says one thing, that the status is finalized. finalizedBy and finalizedAt are
       left out of the comparison on purpose: finalizedBy is the sender, so a second device's would
       differ from the stored one and turn two devices finalizing into a conflict, and finalizedAt is
       only a claim. */
    public function incoming(PushEntry $entry, Model $record): array
    {
        if ($this->isFinalize($entry->fields)) {
            return ['status' => 'finalized'];
        }

        $this->validateTypes($entry->fields);

        return $entry->fields;
    }

    /* finalized and reports-generated are one fact for comparison: the assessment is finalized. A late
       finalize against one that has since moved on to reports-generated is the no-op, not a conflict. */
    public function currentValue(Model $record, string $wire): mixed
    {
        if ($wire === 'status' && in_array($record->status, self::FINALIZED, true)) {
            return 'finalized';
        }

        return parent::currentValue($record, $wire);
    }

    /* A finalize is its own entry, checked before any version rule: it is never a create, it carries
       exactly status and finalizedBy (and optionally finalizedAt), the status is finalized (unlock is
       an administrator action, never a mutation), and finalizedBy is the token's user, which a device
       reports because it records its own action offline but may not claim for someone else. */
    public function check(PushEntry $entry, ?Model $record): void
    {
        if (! $this->isFinalize($entry->fields)) {
            return;
        }

        if ($record === null) {
            throw SyncRejection::invalid('an assessment is created scheduled; finalize is its own entry');
        }

        $names = array_keys($entry->fields);

        if (array_diff($names, self::FINALIZE_NAMES) !== [] || ! in_array('status', $names, true) || ! in_array('finalizedBy', $names, true)) {
            throw SyncRejection::invalid('finalize is sent on its own: status, finalizedBy, finalizedAt');
        }

        if ($entry->fields['status'] !== 'finalized') {
            throw SyncRejection::invalid('status changes only by finalize; unlocking is an administrator action');
        }

        if (! is_string($entry->fields['finalizedBy']) || $entry->fields['finalizedBy'] !== (string) $this->user->id) {
            throw SyncRejection::invalid('finalizedBy must be the signed-in user');
        }
    }

    public function refused(): array
    {
        return ['deletedAt' => 'an assessment cannot be deleted'];
    }

    public function find(string $recordId, bool $lock = false): ?Model
    {
        $query = Assessment::withTrashed();

        return ($lock ? $query->lockForUpdate() : $query)->find($recordId);
    }

    public function authorize(?Model $record): bool
    {
        return Gate::forUser($this->user)->allows($record === null ? 'create' : 'update', $record ?? Assessment::class);
    }

    public function resulting(PushEntry $entry, ?Model $record): Model
    {
        if ($record !== null && $this->isFinalize($entry->fields)) {
            return $this->finalization($record);
        }

        return $record === null ? $this->creation($entry) : $this->revision($entry, $record);
    }

    /* Already finalized: nothing to do, and the record is returned untouched so the save is a no-op
       (the version stays, nothing is logged). Otherwise the same checks as the online finalize, through
       prepareFinalize, which fills the record and leaves the saving to SyncPush. An authorization or
       validation failure there is caught by SyncPush as forbidden or invalid. finalizedAt is never read. */
    private function finalization(Assessment $record): Assessment
    {
        if ($record->trashed()) {
            throw SyncRejection::invalid('the assessment has been deleted');
        }

        if (in_array($record->status, self::FINALIZED, true)) {
            return $record;
        }

        $record->prepareFinalize($this->user);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function isFinalize(array $fields): bool
    {
        return array_intersect(array_keys($fields), self::FINALIZE_NAMES) !== [];
    }

    private function creation(PushEntry $entry): Assessment
    {
        $fields = $entry->fields;

        foreach (self::CREATE_FIELDS as $name) {
            if (! array_key_exists($name, $fields)) {
                throw SyncRejection::invalid("{$name} is required");
            }
        }

        $this->validateTypes($fields);
        $this->assertClassOffersSubject($fields['classId'], $fields['subjectId']);

        return new Assessment([
            'id' => $entry->recordId,
            'institution_id' => $this->user->institution_id,
            'class_id' => $fields['classId'],
            'subject_id' => $fields['subjectId'],
            'name' => $fields['name'],
            'term' => $fields['term'],
            'year' => $fields['year'],
            'date' => $fields['date'],
            'status' => 'scheduled',
            'created_by' => $this->user->id,
        ]);
    }

    /* A patch: only the fields sent are applied, each checked on its own, and the row
       that results is checked as a whole (the scope fields are frozen once marks exist,
       a changed class or subject must still be an offered pair). The row is returned
       with its changes unsaved; SyncPush saves it, and an unchanged field is not dirty,
       so a patch that changes nothing logs nothing and leaves the version alone. */
    private function revision(PushEntry $entry, Assessment $record): Assessment
    {
        // Editing a deleted row at its current version is not specified; refusing it is
        // safer than writing to a row nothing should be able to reach.
        if ($record->trashed()) {
            throw SyncRejection::invalid('the assessment has been deleted');
        }

        $fields = $entry->fields;

        $this->validateTypes($fields);

        $classId = $fields['classId'] ?? (string) $record->class_id;
        $subjectId = $fields['subjectId'] ?? (string) $record->subject_id;
        $year = $fields['year'] ?? (int) $record->year;

        $scopeChanged = $classId !== (string) $record->class_id
            || $subjectId !== (string) $record->subject_id
            || $year !== (int) $record->year;

        if ($scopeChanged && $record->marks()->exists()) {
            throw SyncRejection::invalid('The class, subject, and year cannot change after marks exist.');
        }

        if (array_key_exists('classId', $fields) || array_key_exists('subjectId', $fields)) {
            $this->assertClassOffersSubject($classId, $subjectId);
        }

        $record->fill(collect($fields)->mapWithKeys(fn ($value, $name) => [$this->columns()[$name] => $value])->all());

        return $record;
    }

    private function assertClassOffersSubject(string $classId, string $subjectId): void
    {
        if (SchoolClass::find($classId) === null) {
            throw SyncRejection::invalid('classId does not resolve');
        }

        if (Subject::find($subjectId) === null) {
            throw SyncRejection::invalid('subjectId does not resolve');
        }

        if (! ClassSubject::where('class_id', $classId)->where('subject_id', $subjectId)->exists()) {
            throw SyncRejection::invalid('The selected class does not offer this subject.');
        }
    }

    /**
     * Each field that is present, checked on its own; a create has checked that all are present first.
     *
     * @param  array<string, mixed>  $fields
     */
    private function validateTypes(array $fields): void
    {
        foreach (['classId', 'subjectId'] as $name) {
            if (array_key_exists($name, $fields) && (! is_string($fields[$name]) || ! Str::isUuid($fields[$name]))) {
                throw SyncRejection::invalid("{$name} must be a UUID");
            }
        }

        // Characters, not bytes, as varchar(255) counts them; and no NUL, which Postgres refuses in text.
        if (array_key_exists('name', $fields)
            && (! is_string($fields['name']) || trim($fields['name']) === '' || mb_strlen($fields['name']) > 255 || str_contains($fields['name'], "\0"))) {
            throw SyncRejection::invalid('name must be a non-empty string of at most 255 characters');
        }

        if (array_key_exists('term', $fields) && (! is_int($fields['term']) || $fields['term'] < 1 || $fields['term'] > 3)) {
            throw SyncRejection::invalid('term must be 1, 2, or 3');
        }

        if (array_key_exists('year', $fields) && (! is_int($fields['year']) || $fields['year'] < 1 || $fields['year'] > 9999)) {
            throw SyncRejection::invalid('year must be an integer from 1 to 9999');
        }

        if (array_key_exists('date', $fields)) {
            $date = is_string($fields['date']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $fields['date']) : false;

            // Year 0000 round-trips through PHP but Postgres has no year 0 and refuses it.
            if ($date === false || $date->format('Y-m-d') !== $fields['date'] || (int) $date->format('Y') < 1) {
                throw SyncRejection::invalid('date must be a real date in the form YYYY-MM-DD');
            }
        }
    }
}

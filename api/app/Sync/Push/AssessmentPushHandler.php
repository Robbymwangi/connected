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
   teacher (C4). status and deletedAt are refused: a status moves only by
   finalize, which sync does not accept yet, and no assessment is deleted from a
   device. Every type and range is checked here in PHP, before any query or save,
   because a malformed value reaching Postgres would be a 5xx, not an answer, and a
   5xx on one entry stalls every entry behind it in the outbox on each resend. */
final class AssessmentPushHandler extends PushHandler
{
    public function columns(): array
    {
        return [
            'classId' => 'class_id',
            'subjectId' => 'subject_id',
            'name' => 'name',
            'term' => 'term',
            'year' => 'year',
            'date' => 'date',
        ];
    }

    public function refused(): array
    {
        return [
            'deletedAt' => 'an assessment cannot be deleted',
            'status' => 'status changes only by finalize, which sync does not accept yet',
        ];
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
        if ($record !== null) {
            throw SyncRejection::invalid('assessment updates are not supported yet');
        }

        $fields = $entry->fields;

        foreach (array_keys($this->columns()) as $name) {
            if (! array_key_exists($name, $fields)) {
                throw SyncRejection::invalid("{$name} is required");
            }
        }

        $this->validateTypes($fields);

        if (SchoolClass::find($fields['classId']) === null) {
            throw SyncRejection::invalid('classId does not resolve');
        }

        if (Subject::find($fields['subjectId']) === null) {
            throw SyncRejection::invalid('subjectId does not resolve');
        }

        if (! ClassSubject::where('class_id', $fields['classId'])->where('subject_id', $fields['subjectId'])->exists()) {
            throw SyncRejection::invalid('The selected class does not offer this subject.');
        }

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

    /**
     * @param  array<string, mixed>  $fields
     */
    private function validateTypes(array $fields): void
    {
        foreach (['classId', 'subjectId'] as $name) {
            if (! is_string($fields[$name]) || ! Str::isUuid($fields[$name])) {
                throw SyncRejection::invalid("{$name} must be a UUID");
            }
        }

        // Characters, not bytes, as varchar(255) counts them; and no NUL, which Postgres refuses in text.
        if (! is_string($fields['name']) || trim($fields['name']) === '' || mb_strlen($fields['name']) > 255 || str_contains($fields['name'], "\0")) {
            throw SyncRejection::invalid('name must be a non-empty string of at most 255 characters');
        }

        if (! is_int($fields['term']) || $fields['term'] < 1 || $fields['term'] > 3) {
            throw SyncRejection::invalid('term must be 1, 2, or 3');
        }

        if (! is_int($fields['year']) || $fields['year'] < 1 || $fields['year'] > 9999) {
            throw SyncRejection::invalid('year must be an integer from 1 to 9999');
        }

        $date = is_string($fields['date']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $fields['date']) : false;

        // Year 0000 round-trips through PHP but Postgres has no year 0 and refuses it.
        if ($date === false || $date->format('Y-m-d') !== $fields['date'] || (int) $date->format('Y') < 1) {
            throw SyncRejection::invalid('date must be a real date in the form YYYY-MM-DD');
        }
    }
}

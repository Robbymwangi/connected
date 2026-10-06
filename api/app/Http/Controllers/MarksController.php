<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\Student;
use App\Support\SyncLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarksController extends Controller
{
    /**
     * Create or update a mark after validating enrolment, criterion, and score.
     */
    public function store(Request $request): JsonResponse
    {
        $institutionId = $request->user()->institution_id;

        $attributes = $request->validate([
            'assessment_id' => [
                'required', 'uuid',
                Rule::exists('assessments', 'id')->where('institution_id', $institutionId),
            ],
            'student_id' => [
                'required', 'uuid',
                Rule::exists('students', 'id')->where('institution_id', $institutionId),
            ],
            'criterion_id' => [
                'required', 'uuid',
                Rule::exists('criteria', 'id')->where('institution_id', $institutionId),
            ],
            'mark_kind' => ['required', Rule::in(['empty', 'score', 'absent'])],
            'score' => ['required_if:mark_kind,score', 'prohibited_unless:mark_kind,score', 'integer', 'min:0'],
        ]);

        $assessment = Assessment::findOrFail($attributes['assessment_id']);
        $student = Student::findOrFail($attributes['student_id']);
        $criterion = Criterion::findOrFail($attributes['criterion_id']);

        if ($criterion->subject_id !== $assessment->subject_id) {
            throw ValidationException::withMessages([
                'criterion_id' => 'The criterion must belong to the assessment subject.',
            ]);
        }

        $isEnrolled = Enrolment::query()
            ->where('student_id', $student->id)
            ->where('class_id', $assessment->class_id)
            ->where('year', $assessment->year)
            ->exists();

        if (! $isEnrolled) {
            throw ValidationException::withMessages([
                'student_id' => 'The student must be enrolled in the assessment class and year.',
            ]);
        }

        if ($attributes['mark_kind'] === 'score' && (int) $attributes['score'] > $criterion->max_score) {
            throw ValidationException::withMessages([
                'score' => 'The score may not be greater than the criterion maximum.',
            ]);
        }

        $mark = Mark::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $student->id)
            ->where('criterion_id', $criterion->id)
            ->first();

        Gate::authorize($mark === null ? 'create' : 'update', $mark ?? Mark::class);

        $isNewMark = $mark === null;
        $markAttributes = [
            'mark_kind' => $attributes['mark_kind'],
            'score' => $attributes['mark_kind'] === 'score' ? $attributes['score'] : null,
            'last_edited_by' => $request->user()->id,
        ];

        $mark ??= new Mark;
        $mark->fill([
            'institution_id' => $institutionId,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'criterion_id' => $criterion->id,
            ...$markAttributes,
        ]);

        try {
            SyncLog::transaction($institutionId, function () use ($mark, $isNewMark, $markAttributes): void {
                $this->lockEditableAssessment($mark->assessment_id);

                if (! $isNewMark) {
                    $mark->refresh();
                    $mark->fill($markAttributes);
                }

                $mark->save();
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $isNewMark) {
                throw $exception;
            }

            $mark = SyncLog::transaction($institutionId, function () use ($mark, $markAttributes, $exception): Mark {
                $this->lockEditableAssessment($mark->assessment_id);

                $existingMark = Mark::query()->find($mark->newUniqueId());

                if ($existingMark === null) {
                    throw $exception;
                }

                Gate::authorize('update', $existingMark);
                $existingMark->update($markAttributes);

                return $existingMark;
            });
        }

        return $this->markResponse($mark, $mark->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Update an authorized mark, enforcing the criterion maximum score.
     */
    public function update(Request $request, Mark $mark): JsonResponse
    {
        Gate::authorize('update', $mark);

        $attributes = $request->validate([
            'mark_kind' => ['required', Rule::in(['empty', 'score', 'absent'])],
            'score' => ['required_if:mark_kind,score', 'prohibited_unless:mark_kind,score', 'integer', 'min:0'],
        ]);

        if ($attributes['mark_kind'] === 'score' && (int) $attributes['score'] > $mark->criterion->max_score) {
            throw ValidationException::withMessages([
                'score' => 'The score may not be greater than the criterion maximum.',
            ]);
        }

        SyncLog::transaction($mark->institution_id, function () use ($mark, $attributes, $request): void {
            $this->lockEditableAssessment($mark->assessment_id);
            $mark->refresh();
            $mark->update([
                'mark_kind' => $attributes['mark_kind'],
                'score' => $attributes['mark_kind'] === 'score' ? $attributes['score'] : null,
                'last_edited_by' => $request->user()->id,
            ]);
        });

        return $this->markResponse($mark, 200);
    }

    private function lockEditableAssessment(string $assessmentId): Assessment
    {
        $assessment = Assessment::query()->lockForUpdate()->findOrFail($assessmentId);

        if (in_array($assessment->status, ['finalized', 'reports-generated'], true)) {
            throw ValidationException::withMessages([
                'assessment_id' => 'Marks cannot be changed while the assessment is finalized. Unlock it first.',
            ]);
        }

        return $assessment;
    }

    /**
     * Return the mark identifiers, value, and version with the given HTTP status.
     */
    private function markResponse(Mark $mark, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $mark->id,
                'assessment_id' => $mark->assessment_id,
                'student_id' => $mark->student_id,
                'criterion_id' => $mark->criterion_id,
                'mark_kind' => $mark->mark_kind,
                'score' => $mark->score,
                'version' => $mark->version,
            ],
        ], $status);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use App\Models\ClassSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/* GET /api/assessments (build plan 2.1, #48). Unrestricted within the
   institution for any authenticated user (docs/spec/access-model.md, The
   principle); creating and grading an assessment stay unrestricted too,
   this endpoint only reads. Optional class_id narrows to one class's
   sittings. */
class AssessmentsController extends Controller
{
    /**
     * List institution assessments, optionally filtered by class.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'class_id' => ['nullable', 'uuid'],
        ]);

        $assessments = Assessment::with('subject')
            ->when($filters['class_id'] ?? null, fn ($query, $classId) => $query->where('class_id', $classId))
            ->get();

        return AssessmentResource::collection($assessments);
    }

    /**
     * Create a scheduled assessment after validating the class and subject.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Assessment::class);

        $attributes = $this->validatedAttributes($request);
        $this->ensureSubjectIsOffered($attributes);

        $assessment = Assessment::create([
            ...$attributes,
            'institution_id' => $request->user()->institution_id,
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ]);

        return AssessmentResource::make($assessment->load('subject'))->response()->setStatusCode(201);
    }

    /**
     * Update an assessment after validating the class and subject.
     */
    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('update', $assessment);

        $attributes = $this->validatedAttributes($request);
        $this->ensureSubjectIsOffered($attributes);

        $assessment->update($attributes);

        return AssessmentResource::make($assessment->load('subject'))->response()->setStatusCode(200);
    }

    /**
     * Validate assessment fields and institution membership of related records.
     */
    private function validatedAttributes(Request $request): array
    {
        $institutionId = $request->user()->institution_id;

        return $request->validate([
            'class_id' => [
                'required', 'uuid',
                Rule::exists('classes', 'id')->where('institution_id', $institutionId),
            ],
            'subject_id' => [
                'required', 'uuid',
                Rule::exists('subjects', 'id')->where('institution_id', $institutionId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'term' => ['required', 'integer', 'between:1,3'],
            'year' => ['required', 'integer', 'min:1', 'max:65535'],
            'date' => ['required', 'date'],
        ]);
    }

    /**
     * Reject a subject that is not offered by the selected class.
     */
    private function ensureSubjectIsOffered(array $attributes): void
    {
        $isOffered = ClassSubject::query()
            ->where('class_id', $attributes['class_id'])
            ->where('subject_id', $attributes['subject_id'])
            ->exists();

        if (! $isOffered) {
            throw ValidationException::withMessages([
                'subject_id' => 'The class must offer the selected subject.',
            ]);
        }
    }
}

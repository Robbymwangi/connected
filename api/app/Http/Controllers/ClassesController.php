<?php

namespace App\Http\Controllers;

use App\Http\Resources\SchoolClassResource;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/* GET /api/classes (build plan 2.1, #48). Unrestricted within the
   institution for any authenticated user (docs/spec/access-model.md, The
   principle); InstitutionScope does the actual filtering, not a condition
   here. */
class ClassesController extends Controller
{
    /**
     * List institution classes with their teachers and offered subjects.
     */
    public function __invoke(): AnonymousResourceCollection
    {
        $classes = SchoolClass::with(['classTeacher', 'classSubjects.subject'])->get();

        return SchoolClassResource::collection($classes);
    }

    /**
     * Create an institution class after authorizing administrator access.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', SchoolClass::class);

        $schoolClass = SchoolClass::create([
            ...$this->validatedAttributes($request),
            'institution_id' => $request->user()->institution_id,
        ]);

        return $this->resourceResponse($schoolClass, 201);
    }

    /**
     * Update a class after authorizing administrator access.
     */
    public function update(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        Gate::authorize('update', $schoolClass);

        $schoolClass->update($this->validatedAttributes($request));

        return $this->resourceResponse($schoolClass, 200);
    }

    /**
     * Validate class fields and institution membership of the optional teacher.
     */
    private function validatedAttributes(Request $request): array
    {
        $institutionId = $request->user()->institution_id;

        return $request->validate([
            'grade' => ['required', 'string', 'max:255'],
            'stream' => ['required', 'string', 'max:255'],
            'class_teacher_id' => [
                'nullable', 'uuid',
                Rule::exists('users', 'id')->where('institution_id', $institutionId),
            ],
        ]);
    }

    /**
     * Return the class resource with its teacher, subjects, and HTTP status.
     */
    private function resourceResponse(SchoolClass $schoolClass, int $status): JsonResponse
    {
        return SchoolClassResource::make(
            $schoolClass->load(['classTeacher', 'classSubjects.subject']),
        )->response()->setStatusCode($status);
    }
}

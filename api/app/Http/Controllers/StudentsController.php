<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/* GET /api/students (build plan 2.1, #48). Unrestricted within the
   institution for any authenticated user (docs/spec/access-model.md, The
   principle). Optional class_id and year filter to a roster
   (frontend/src/fixtures/students.ts's rosterFor()), matched through
   enrolments: which class a student sits in is a fact of a year, not of
   the student (docs/spec/data-model.md, enrolments), so it is never a
   column on students to filter by directly. With neither filter, a plain
   student list carries no enrolments at all.

   class_id without an explicit year defaults to the current year
   (docs/spec/data-model.md: "rosterFor(classId) becomes a query against
   this table filtered to the current year"), so a student who sat in this
   class in a prior year does not appear in today's roster. An explicit
   year overrides that default, for a historical roster. */
class StudentsController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'class_id' => ['nullable', 'uuid'],
            'year' => ['nullable', 'integer', 'min:1'],
        ]);

        $classId = $filters['class_id'] ?? null;
        $year = $filters['year'] ?? ($classId ? now()->year : null);

        $constrain = function ($query) use ($classId, $year) {
            $query->when($classId, fn ($q, $classId) => $q->where('class_id', $classId))
                ->when($year, fn ($q, $year) => $q->where('year', $year));
        };

        $query = Student::query();

        if ($classId || $year) {
            $query->whereHas('enrolments', $constrain)->with(['enrolments' => $constrain]);
        }

        return StudentResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Student::class);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:F,M'],
            'dob' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $student = Student::create([
            ...$attributes,
            'institution_id' => $request->user()->institution_id,
        ]);

        return StudentResource::make($student)->response()->setStatusCode(201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('update', $student);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:F,M'],
            'dob' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $student->update($attributes);

        return StudentResource::make($student)->response()->setStatusCode(200);
    }
}

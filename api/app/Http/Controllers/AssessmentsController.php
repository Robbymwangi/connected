<?php

namespace App\Http\Controllers;

use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/* GET /api/assessments (build plan 2.1, #48). Unrestricted within the
   institution for any authenticated user (docs/spec/access-model.md, The
   principle); creating and grading an assessment stay unrestricted too,
   this endpoint only reads. Optional class_id narrows to one class's
   sittings. */
class AssessmentsController extends Controller
{
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
}

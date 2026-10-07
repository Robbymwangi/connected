<?php

namespace App\Http\Controllers;

use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/* GET /api/assessments (build plan 2.1, #48). Unrestricted within the
   institution for any authenticated user (docs/spec/access-model.md, The
   principle). Optional class_id narrows to one class's sittings. Assessments
   are created and edited over POST /sync (3.2), never here; unlock stays an
   online administrator action. */
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

    public function unlock(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('unlock', $assessment);

        $attributes = $request->validate([
            'note' => ['sometimes', 'nullable', 'string'],
        ]);

        $assessment->unlock($request->user(), $attributes['note'] ?? null);

        return AssessmentResource::make($assessment->load('subject'))->response()->setStatusCode(200);
    }
}

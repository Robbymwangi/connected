<?php

namespace App\Http\Controllers;

use App\Support\AnalyticsFilters;
use App\Support\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsSummaryController extends Controller
{
    public function __invoke(Request $request, AnalyticsQueryService $analytics): JsonResponse
    {
        $institutionId = $request->user()->institution_id;
        $validated = $request->validate([
            'stream' => [
                'required',
                'string',
                Rule::exists('classes', 'stream')
                    ->where('institution_id', $institutionId)
                    ->whereNull('deleted_at'),
            ],
            'subject_id' => [
                'nullable',
                'uuid',
                Rule::exists('subjects', 'id')
                    ->where('institution_id', $institutionId)
                    ->whereNull('deleted_at'),
            ],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'term' => ['nullable', 'integer', 'between:1,3'],
            'assessment_name' => ['nullable', 'string', 'max:255'],
        ]);

        $filters = new AnalyticsFilters(
            $institutionId,
            $validated['stream'],
            $validated['subject_id'] ?? null,
            (int) $validated['year'],
            isset($validated['term']) ? (int) $validated['term'] : null,
            $validated['assessment_name'] ?? null,
        );

        return response()->json([
            'filters' => [
                'stream' => $filters->stream,
                'subject_id' => $filters->subjectId,
                'year' => $filters->year,
                'term' => $filters->term,
                'assessment_name' => $filters->assessmentName,
            ],
            'available_years' => $analytics->availableYears($institutionId, $filters->stream, $filters->subjectId),
            'available_assessments' => $analytics->availableAssessmentNames($filters),
            'summary' => [
                'pass_rate' => $analytics->passRate($filters),
                'mean_score' => $analytics->meanScore($filters),
                'score_spread' => $analytics->scoreSpread($filters),
                'entry_completeness' => $analytics->entryCompleteness($filters),
                'performance_levels' => $analytics->performanceLevels($filters),
                'histogram' => $analytics->histogram($filters),
                'trend' => $analytics->trend($filters),
                'criterion_breakdown' => $analytics->criterionBreakdown($filters),
                'attention_list' => $analytics->attentionList($filters),
                'decline_list' => $analytics->declineList($filters),
                'net_level_movement' => $analytics->netLevelMovement($filters),
            ],
        ]);
    }
}

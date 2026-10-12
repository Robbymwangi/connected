<?php

namespace App\Http\Controllers;

use App\Support\AnalyticsFilters;
use App\Support\Assistant\Conversation;
use App\Support\Assistant\ReportAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/* POST /api/reports/assistant (ADR 0013, decision 2): one question about the report on screen.
   The request carries the report's scope, validated exactly as GET /api/reports/summary validates
   it, so the assistant can never be pointed at another stream or school; the new question; and the
   conversation id this server issued, if any. History stays on the server. Without a key there is
   no assistant: 503, and the device falls back to its labelled stand-in. */
class ReportAssistantController extends Controller
{
    public function __invoke(Request $request, ReportAssistant $assistant): JsonResponse
    {
        $institutionId = $request->user()->institution_id;
        $validated = $request->validate([
            'stream' => ['required', 'string', Rule::exists('classes', 'stream')->where('institution_id', $institutionId)->whereNull('deleted_at')],
            'subject_id' => ['nullable', 'uuid', Rule::exists('subjects', 'id')->where('institution_id', $institutionId)->whereNull('deleted_at')],
            'assessment_id' => ['nullable', 'uuid', Rule::exists('assessments', 'id')->where('institution_id', $institutionId)->whereNull('deleted_at')],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'term' => ['nullable', 'integer', 'between:1,3'],
            'assessment_name' => ['nullable', 'string', 'max:255'],
            'question' => ['required', 'string', 'max:500'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);

        if (blank(config('services.anthropic.key'))) {
            return response()->json(['message' => 'The report assistant is not configured on this server.', 'code' => 'assistant_unavailable'], 503);
        }

        $filters = new AnalyticsFilters(
            $institutionId,
            $validated['stream'],
            $validated['subject_id'] ?? null,
            (int) $validated['year'],
            isset($validated['term']) ? (int) $validated['term'] : null,
            $validated['assessment_name'] ?? null,
            $validated['assessment_id'] ?? null,
        );
        $scopeKey = implode('|', [$filters->stream, $filters->subjectId, $filters->year, $filters->term, $filters->assessmentName, $filters->assessmentId]);
        $conversation = Conversation::resume($validated['conversation_id'] ?? null, (string) $request->user()->id, $scopeKey);

        $answer = $assistant->answer(trim($validated['question']), $filters, $conversation);

        return response()->json(['data' => [
            'conversationId' => $conversation->id,
            'answerable' => $answer['answerable'],
            'text' => $answer['text'],
            'chart' => $answer['chart'],
        ]]);
    }
}

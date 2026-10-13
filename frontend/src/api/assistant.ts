import { ApiError, apiFetch, type FetchImpl } from './client'
import type { ReportSummaryFilters } from './reports'

export type AssistantReply = {
  conversationId: string
  answerable: boolean
  text: string
  chart: 'trend' | 'criteria' | null
}

/* One question to the report assistant (ADR 0013): the report's scope as the summary was asked
   for, the new question, and the conversation id the server issued, if any. The server holds the
   history; nothing else is sent. A live call can take a while: the model may look up several
   figures before it answers. */
export function askAssistant(
  filters: ReportSummaryFilters,
  question: string,
  conversationId: string | null,
  token: string,
  fetchImpl?: FetchImpl,
): Promise<{ data: AssistantReply }> {
  return apiFetch<{ data: AssistantReply }>('/api/reports/assistant', {
    method: 'POST',
    token,
    timeoutMs: 60_000,
    body: {
      stream: filters.stream,
      year: filters.year,
      subject_id: filters.subjectId ?? null,
      term: filters.term ?? null,
      assessment_name: filters.assessmentName ?? null,
      assessment_id: filters.assessmentId ?? null,
      question,
      conversation_id: conversationId,
    },
  }, fetchImpl)
}

/* The server has no model key: the device falls back to its labelled stand-in. */
export function isAssistantUnavailable(error: unknown): boolean {
  return error instanceof ApiError && error.status === 503 &&
    typeof error.body === 'object' && error.body !== null && (error.body as { code?: unknown }).code === 'assistant_unavailable'
}

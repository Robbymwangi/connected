import { apiFetch, type FetchImpl } from './client'

export type TermCloseResponse = {
  data: { id: string; class_id: string; year: number; term: number; closed_by: string; closed_at: string | null; version: number }
  meta: { comments: number; skipped: number }
}

/* Closes one stream's term (docs/spec/workflow.md, Term close): online only, outside the
   outbox, because it needs a check across the term's assessments and has server-side effects.
   The closure and its pending comments then reach the device by pull like any other row. */
export function closeTerm(
  input: { classId: string; year: number; term: number },
  token: string,
  fetchImpl?: FetchImpl,
): Promise<TermCloseResponse> {
  return apiFetch<TermCloseResponse>('/api/term-closures', {
    method: 'POST',
    token,
    body: { class_id: input.classId, year: input.year, term: input.term },
  }, fetchImpl)
}

import { apiFetch, type FetchImpl } from './client'

export type UnlockResponse = {
  data: { id: string; status: string; version: number }
}

/* Unlocks a finalized assessment (docs/spec/workflow.md, Unlock; ADR 0014, decision 3): online
   only, outside the outbox, like the term close, because it undoes server-side work (results,
   and a closed term's comments and cards). The reopened assessment then reaches the device by
   pull like any other row. The note is required once report cards exist; the server says so. */
export function unlockAssessment(
  assessmentId: string,
  note: string,
  token: string,
  fetchImpl?: FetchImpl,
): Promise<UnlockResponse> {
  const trimmed = note.trim()
  return apiFetch<UnlockResponse>(`/api/assessments/${encodeURIComponent(assessmentId)}/unlock`, {
    method: 'POST',
    token,
    body: trimmed === '' ? {} : { note: trimmed },
  }, fetchImpl)
}

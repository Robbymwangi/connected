import type { LocalRecord } from './localDatabase'

/* Whether a stream's term is closed, read from the closures this device already holds
   (docs/spec/workflow.md, Term close). The server refuses an assessment created in, or
   moved into, a closed term; this lets the create dialog say so before the change is
   queued, instead of after the push as a failed change. It is a courtesy: a device that
   has not synced since the close does not know, and the server, which stays the authority,
   still refuses. */
export function isTermClosed(closures: readonly LocalRecord[], classId: string, year: number, term: number): boolean {
  return closures.some((row) => row.deletedAt == null && row.classId === classId && row.year === year && row.term === term)
}

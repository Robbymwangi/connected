import { useState } from 'react'
import { assessments as seedAssessments, type Assessment } from '../fixtures/assessments'
import { classes } from '../fixtures/classes'
import { activeConflicts, type ActiveConflict } from '../fixtures/conflicts'
import { emptyGrid, marksByAssessment, type Grid } from '../fixtures/marks'
import { rubricFor } from '../fixtures/rubrics'
import { rosterFor } from '../fixtures/students'

/* Everything the screens mutate, held in one place for the life of the session so
   a change made on one screen is still there after navigating away and back. It is
   seeded from fixtures and lives in memory; the IndexedDB store with its outbox
   replaces this hook without changing what the screens receive. Not a state
   library: one useState per record type and plain functions over them. */
export function useSessionStore() {
  const [assessments, setAssessments] = useState<Assessment[]>(seedAssessments)
  const [marks, setMarks] = useState<Record<string, Grid>>(marksByAssessment)
  const [conflicts, setConflicts] = useState<ActiveConflict[]>(activeConflicts)

  const emptyGridFor = (assessmentId: string): Grid => {
    const a = assessments.find((x) => x.id === assessmentId)
    const cls = a && classes.find((c) => c.stream === a.stream)
    return emptyGrid(
      rosterFor(cls?.id ?? '').map((s) => s.id),
      a ? rubricFor(a.subject).map((c) => c.id) : [],
    )
  }

  return {
    assessments,
    /* Primary keys are client-generated UUIDs, assigned here at creation; a record
       made offline cannot wait for a server to number it. */
    addAssessments: (drafts: Omit<Assessment, 'id'>[]) =>
      setAssessments((prev) => [
        ...drafts.map((d) => ({ ...d, id: crypto.randomUUID() })),
        ...prev,
      ]),
    finalizeAssessment: (id: string) =>
      setAssessments((prev) =>
        prev.map((a) => (a.id === id ? { ...a, status: 'finalized', sync: 'pending' } : a)),
      ),

    gridFor: (assessmentId: string): Grid => marks[assessmentId] ?? emptyGridFor(assessmentId),
    updateGrid: (assessmentId: string, update: (grid: Grid) => Grid) =>
      setMarks((prev) => ({
        ...prev,
        [assessmentId]: update(prev[assessmentId] ?? emptyGridFor(assessmentId)),
      })),

    conflicts,
    resolveConflict: (id: string) => setConflicts((prev) => prev.filter((k) => k.id !== id)),
  }
}

export type SessionStore = ReturnType<typeof useSessionStore>

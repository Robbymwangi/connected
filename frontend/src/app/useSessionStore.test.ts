import { describe, expect, it } from 'vitest'
import { assessments } from '../fixtures/assessments'
import { classes } from '../fixtures/classes'
import { activeConflicts, resolvedConflicts } from '../fixtures/conflicts'
import { marksByAssessment } from '../fixtures/marks'
import { rubricFor } from '../fixtures/rubrics'
import { subjects } from '../fixtures/rubrics'
import { rosterFor } from '../fixtures/students'
import { score } from '../lib/grading'
import { emptyGrid } from '../lib/localMarks'
import { reduce, mergeSyncedState } from './useSessionStore'

/* The conflict policy that used to be tested here through the reducer now lives in
   lib/conflicts.ts (planCommand) and is tested there; the commands are queued and the
   conflict is shown with them applied, which lib/conflictCommands.ts and
   lib/syncedAssessmentState.ts test. What is left here is what the store itself does. */

const seed: Parameters<typeof reduce>[0] = {
  assessments,
  marks: marksByAssessment,
  conflicts: activeConflicts,
  history: resolvedConflicts,
  criteriaBySubject: Object.fromEntries(subjects.map((subject) => [subject, rubricFor(subject)])),
  resultRecords: [],
  loadFailed: false,
}

describe('addAssessments', () => {
  it('assigns a UUID and version 0 to each created record', () => {
    const { id: _id, version: _v, ...draft } = seed.assessments[0]
    const next = reduce(seed, { type: 'addAssessments', drafts: [draft] })
    expect(next.assessments[0].version).toBe(0)
    expect(next.assessments[0].id).toMatch(/^[0-9a-f-]{36}$/)
  })
})

describe('updateGrid', () => {
  it('seeds an empty grid for an assessment with no marks yet', () => {
    const scheduled = seed.assessments.find((a) => a.status === 'scheduled')!
    const cls = classes.find((item) => item.stream === scheduled.stream)!
    const fallback = emptyGrid(rosterFor(cls.id).map((student) => student.id), rubricFor(scheduled.subject).map((criterion) => criterion.id))
    const next = reduce(seed, { type: 'updateGrid', assessmentId: scheduled.id, update: (g) => g, emptyGrid: fallback })
    const grid = next.marks[scheduled.id]
    expect(Object.keys(grid)).toHaveLength(28)
    expect(Object.values(grid)[0].sc1.mark.kind).toBe('empty')
  })
})

describe('mergeSyncedState', () => {
  it('takes marks, conflicts, and history from the snapshot, which is the database', () => {
    const conflict = seed.conflicts[0]
    const remote = {
      ...seed,
      marks: {
        ...seed.marks,
        [conflict.assessmentId]: {
          ...seed.marks[conflict.assessmentId],
          [conflict.studentId]: {
            ...seed.marks[conflict.assessmentId][conflict.studentId],
            c1: { mark: score(4), sync: 'synced' as const },
          },
        },
      },
      conflicts: [{ ...conflict, proposals: [] }],
      history: [],
    }
    const current = {
      ...seed,
      marks: {
        ...seed.marks,
        [conflict.assessmentId]: {
          ...seed.marks[conflict.assessmentId],
          [conflict.studentId]: {
            ...seed.marks[conflict.assessmentId][conflict.studentId],
            c1: { mark: score(8), sync: 'local' as const },
          },
        },
      },
    }

    const merged = mergeSyncedState(remote, current)

    expect(merged.marks[conflict.assessmentId][conflict.studentId].c1).toEqual({ mark: score(4), sync: 'synced' })
    expect(merged.conflicts).toEqual(remote.conflicts)
    expect(merged.history).toEqual([])
  })

  it('takes an assessment the database reports synced, and keeps one the database does not have yet', () => {
    const syncedHere = { ...seed.assessments[0], sync: 'pending' as const }
    const notStored = { ...seed.assessments[1], id: 'only-in-memory', version: 0, sync: 'pending' as const }
    const current = { ...seed, assessments: [notStored, syncedHere, ...seed.assessments.slice(1)] }
    const remote = { ...seed, assessments: seed.assessments.map((assessment) => ({ ...assessment, sync: 'synced' as const })) }

    const merged = mergeSyncedState(remote, current)

    expect(merged.assessments.find((assessment) => assessment.id === syncedHere.id)?.sync).toBe('synced')
    expect(merged.assessments.find((assessment) => assessment.id === 'only-in-memory')).toMatchObject({ version: 0, sync: 'pending' })
    expect(merged.assessments.filter((assessment) => assessment.id === syncedHere.id)).toHaveLength(1)
  })

  it('drops an in-memory assessment the database does not have once it is synced', () => {
    const gone = { ...seed.assessments[1], id: 'gone', version: 3, sync: 'synced' as const }

    const merged = mergeSyncedState(seed, { ...seed, assessments: [gone, ...seed.assessments] })

    expect(merged.assessments.some((assessment) => assessment.id === 'gone')).toBe(false)
  })

  it('merges a sync hydration against the reducer state at dispatch time', () => {
    const local = reduce(seed, { type: 'addAssessments', drafts: [] })
    const assessmentId = seed.assessments[0].id
    const remote = {
      ...seed,
      assessments: seed.assessments.map((assessment) => assessment.id === assessmentId
        ? { ...assessment, name: 'Updated remotely' }
        : assessment),
    }

    const hydrated = reduce(local, { type: 'hydrate', state: remote })

    expect(hydrated.assessments.find((assessment) => assessment.id === assessmentId)?.name).toBe('Updated remotely')
  })
})


describe('a failed read of the database', () => {
  it('is remembered, and keeps what was already shown rather than blanking it', () => {
    const failed = reduce(seed, { type: 'loadFailed' })

    expect(failed.loadFailed).toBe(true)
    expect(failed.conflicts).toBe(seed.conflicts)
    expect(failed.assessments).toBe(seed.assessments)
  })

  it('clears itself when a later read succeeds', () => {
    const failed = reduce(seed, { type: 'loadFailed' })

    const recovered = reduce(failed, { type: 'hydrate', state: seed })

    expect(recovered.loadFailed).toBe(false)
  })

  it('does not carry the flag in from a snapshot', () => {
    const snapshot = { ...seed, loadFailed: true }

    expect(reduce(seed, { type: 'hydrate', state: snapshot }).loadFailed).toBe(false)
  })
})

import { liveQuery } from 'dexie'
import { useEffect, useMemo, useReducer, useRef, useState } from 'react'
import type { Assessment } from '../fixtures/assessments'
import { type ActiveConflict, type Choice, type HistoricalConflict } from '../fixtures/conflicts'
import type { Grid } from '../fixtures/marks'
import type { Criterion } from '../fixtures/rubrics'
import { requestConflictCommand } from '../lib/conflictCommands'
import { groupConflicts, type CommandRequest, type Resolver } from '../lib/conflicts'
import { localDatabaseFor } from '../lib/localDatabase'
import { createLocalAssessmentRecord } from '../lib/localAssessment'
import { changedGridCells, emptyGrid, gridFromMarkRows, mergePendingMarkCells, type GridCellChange } from '../lib/localMarks'
import { createAssessments, finalizeAssessmentRecord, writeMarkCells } from '../lib/localWrites'
import { markIdFor } from '../lib/markIdentity'
import { mapSyncedAssessmentState, type SyncedAssessmentState } from '../lib/syncedAssessmentState'
import type { SchoolDirectoryState } from './useSchoolDirectory'

/* Everything the screens read, held in one place for the life of the session so a
   change made on one screen is still there after navigating away and back. The
   database is the authority: every write goes to it first (and to the outbox in the same
   transaction), and what this holds is a snapshot of it, re-read whenever it changes.
   A conflict command is the same: it is queued, and the conflict is shown with it
   applied (lib/conflictCommands.ts) until the server's own row says the same.

   A reducer rather than a useState per record type, so a snapshot replaces the
   assessments, marks, and conflicts together. Not a state library. */

type State = {
  assessments: Assessment[]
  marks: Record<string, Grid>
  conflicts: ActiveConflict[]
  history: HistoricalConflict[]
  criteriaBySubject: Record<string, Criterion[]>
  resultRecords: SyncedAssessmentState['resultRecords']
  /* The last read of the database failed, so what is shown may be out of date or
     incomplete. Kept alongside what was last read, not instead of it. */
  loadFailed: boolean
}

const emptyState: State = {
  assessments: [],
  marks: {},
  conflicts: [],
  history: [],
  criteriaBySubject: {},
  resultRecords: [],
  loadFailed: false,
}

type Action =
  | { type: 'hydrate'; state: SyncedAssessmentState }
  | { type: 'loadFailed' }
  | { type: 'addAssessments'; drafts: Omit<Assessment, 'id' | 'version'>[]; ids?: string[] }
  | { type: 'finalizeAssessment'; id: string }
  | { type: 'updateGrid'; assessmentId: string; update: (grid: Grid) => Grid; emptyGrid?: Grid }
  | { type: 'patchGrid'; assessmentId: string; changes: GridCellChange[]; emptyGrid: Grid }

function emptyGridFor(state: State, assessmentId: string, directory: SchoolDirectoryState): Grid {
  const a = state.assessments.find((x) => x.id === assessmentId)
  if (!a || directory.status !== 'ready') return {}
  const cls = directory.data.classesForYear(a.year).find((item) => item.stream === a.stream)
  if (!cls) return {}
  const students = directory.data.studentsForYear(a.year).filter((student) => student.classId === cls.id)
  const criteria = directory.data.criteriaBySubject[a.subject] ?? []
  return emptyGrid(students.map((student) => student.id), criteria.map((criterion) => criterion.id))
}

function gridOf(state: State, assessmentId: string, fallback: Grid = {}): Grid {
  return state.marks[assessmentId] ?? fallback
}

/* The maximum for the criterion a conflict is about; 0 when unknown, which makes
   every corrected score invalid rather than accepting one blindly. */
function criterionMax(state: State, conflict: ActiveConflict): number {
  const a = state.assessments.find((x) => x.id === conflict.assessmentId)
  return a ? (state.criteriaBySubject[a.subject]?.find((c) => c.id === conflict.criterionId)?.max ?? 0) : 0
}

export function reduce(state: State, action: Action): State {
  switch (action.type) {
    case 'hydrate':
      return mergeSyncedState(action.state, state)

    case 'loadFailed':
      return { ...state, loadFailed: true }

    /* Primary keys are client-generated UUIDs, assigned here at creation; a record
       made offline cannot wait for a server to number it. Version 0 means the server
       has never acknowledged it (ADR 0001). */
    case 'addAssessments':
      {
        const existingIds = new Set(state.assessments.map((assessment) => assessment.id))
        const additions = action.drafts
          .map((draft, index) => ({ ...draft, id: action.ids?.[index] ?? crypto.randomUUID(), version: 0 }))
          .filter((assessment) => !existingIds.has(assessment.id))
      return {
        ...state,
          assessments: [...additions, ...state.assessments],
      }
      }

    case 'finalizeAssessment':
      return {
        ...state,
        assessments: state.assessments.map((a) =>
          a.id === action.id ? { ...a, status: 'finalized', sync: 'pending' } : a,
        ),
      }

    case 'updateGrid':
      return {
        ...state,
        marks: { ...state.marks, [action.assessmentId]: action.update(gridOf(state, action.assessmentId, action.emptyGrid)) },
      }

    case 'patchGrid': {
      const grid: Grid = Object.fromEntries(Object.entries(gridOf(state, action.assessmentId, action.emptyGrid)).map(([studentId, row]) => [studentId, { ...row }]))
      for (const change of action.changes) {
        grid[change.studentId] ??= {}
        grid[change.studentId][change.criterionId] = change.cell
      }
      return { ...state, marks: { ...state.marks, [action.assessmentId]: grid } }
    }

  }
}

/* The snapshot from the database replaces what is held. The one thing it keeps from
   before is an assessment the database does not have yet, which a snapshot taken a
   moment before a create commits would otherwise drop. */
export function mergeSyncedState(remote: SyncedAssessmentState, current: State): State {
  const remoteIds = new Set(remote.assessments.map((assessment) => assessment.id))
  const assessments = [
    ...current.assessments.filter((assessment) => !remoteIds.has(assessment.id) && (assessment.version === 0 || assessment.sync === 'pending')),
    ...remote.assessments,
  ]
  return { ...remote, assessments, loadFailed: false }
}

export function useSessionStore(user: Resolver, directory: SchoolDirectoryState) {
  const database = localDatabaseFor(user.id)
  const [state, dispatch] = useReducer(reduce, emptyState)
  const stateRef = useRef(state)
  const [hasHydrated, setHasHydrated] = useState(false)
  const markWriteQueue = useRef(Promise.resolve())
  const ready = hasHydrated || directory.status === 'error'

  const commit = (action: Action) => {
    stateRef.current = reduce(stateRef.current, action)
    dispatch(action)
  }

  useEffect(() => {
    if (directory.status !== 'ready') return

    const subscription = liveQuery(() => database.transaction(
      'r',
      [database.assessments, database.marks, database.conflicts, database.results, database.outbox],
      async () => {
        const [assessments, marks, conflicts, results, commands] = await Promise.all([
          database.assessments.toArray(),
          database.marks.toArray(),
          database.conflicts.toArray(),
          database.results.toArray(),
          /* The conflict commands this device has queued, sent, or had answered but not
             yet pulled: they are shown on top of the conflict rows. */
          database.outbox.where('state').anyOf(['queued', 'sent', 'acked'])
            .filter((entry) => entry.kind === 'command' && entry.table === 'conflicts').toArray(),
        ])
        return mapSyncedAssessmentState({
          assessments, marks, conflicts, results, commands, directory: directory.data, userId: user.id, userName: user.name,
        })
      },
    )).subscribe({
      next: (remote) => {
        commit({ type: 'hydrate', state: remote })
        setHasHydrated(true)
      },
      error: () => {
        commit({ type: 'loadFailed' })
        setHasHydrated(true)
      },
    })

    return () => subscription.unsubscribe()
  }, [database, directory, user.id, user.name])

  const requestCommand = (id: string, request: CommandRequest): Promise<boolean> => {
    const conflict = stateRef.current.conflicts.find((item) => item.id === id)
    const max = conflict ? criterionMax(stateRef.current, conflict) : 0
    return requestConflictCommand(database, conflict, request, user, max, new Date().toISOString())
  }

  /* Which conflicts need this person, which only wait on others, and which they can only
     read. One answer for the top bar, the dashboard, and the Sync screen. */
  const groups = useMemo(
    () => groupConflicts(state.conflicts, { id: user.id, name: user.name, moderatedSubjects: user.moderatedSubjects }),
    [state.conflicts, user.id, user.name, user.moderatedSubjects],
  )

  return {
    ready,
    assessments: state.assessments,
    marks: state.marks,
    conflicts: state.conflicts,
    groups,
    history: state.history,
    criteriaBySubject: state.criteriaBySubject,
    resultRecords: state.resultRecords,
    loadFailed: state.loadFailed,

    addAssessments: async (drafts: Omit<Assessment, 'id' | 'version'>[]) => {
      if (!drafts.length) return
      if (directory.status !== 'ready') throw new Error('School data is unavailable on this device')

      const items = drafts.map((draft) => createLocalAssessmentRecord(draft, crypto.randomUUID(), directory.data))
      await createAssessments(database, items, new Date().toISOString())
      commit({ type: 'addAssessments', drafts, ids: items.map((item) => item.record.id) })
    },
    finalizeAssessment: async (id: string) => {
      const assessment = state.assessments.find((item) => item.id === id)
      if (!assessment) throw new Error('Assessment is not available on this device')
      if (assessment.status === 'finalized' || assessment.status === 'reports-generated') return

      const saved = await finalizeAssessmentRecord(database, id, user.id, new Date().toISOString())
      if (!saved) return
      commit({ type: 'finalizeAssessment', id })
    },

    gridFor: (assessmentId: string): Grid => {
      const assessment = state.assessments.find((item) => item.id === assessmentId)
      return gridOf(state, assessmentId, assessment ? emptyGridFor(state, assessmentId, directory) : {})
    },
    updateGrid: (assessmentId: string, update: (grid: Grid) => Grid): Promise<void> => {
      const persist = async () => {
        const currentState = stateRef.current
        const assessment = currentState.assessments.find((item) => item.id === assessmentId)
        if (!assessment || directory.status !== 'ready') throw new Error('Assessment grid is unavailable on this device')

        const emptyGrid = emptyGridFor(currentState, assessmentId, directory)
        const displayedGrid = gridOf(currentState, assessmentId, emptyGrid)
        const storedRows = await database.marks.where('assessmentId').equals(assessmentId).toArray()
        const teachers = new Map(directory.data.teachers.map((teacher) => [teacher.id, teacher.name]))
        const currentGrid = mergePendingMarkCells(displayedGrid, gridFromMarkRows(storedRows, emptyGrid, teachers))
        const changes = changedGridCells(currentGrid, update(currentGrid))
        if (changes.length === 0) return

        const identifiedChanges = await Promise.all(changes.map(async (change) => ({
          ...change,
          id: await markIdFor(assessmentId, change.studentId, change.criterionId),
        })))

        const savedCells = await writeMarkCells(database, assessmentId, identifiedChanges, user.name, new Date().toISOString())

        commit({ type: 'patchGrid', assessmentId, changes: savedCells, emptyGrid })
      }

      const operation = markWriteQueue.current.then(persist, persist)
      markWriteQueue.current = operation.then(() => undefined, () => undefined)
      return operation
    },

    /* Each asks the policy whether this user may, and if so queues one command against
       the version of the conflict they were shown, and resolves to whether it did. Nothing
       changes in memory: the live query re-reads the database and the conflict is shown
       with the command applied. */
    resolveConflict: (id: string, choice: Choice, note = '') => requestCommand(id, { action: 'resolve', choice, note }),
    proposeResolution: (id: string, choice: Choice, note: string) => requestCommand(id, { action: 'propose', choice, note }),
    acceptProposal: (id: string) => requestCommand(id, { action: 'accept' }),
    referConflict: (id: string) => requestCommand(id, { action: 'refer' }),
  }
}

export type SessionStore = ReturnType<typeof useSessionStore>

import { liveQuery } from 'dexie'
import { useEffect, useReducer, useRef, useState } from 'react'
import type { Assessment } from '../fixtures/assessments'
import {
  type ActiveConflict,
  type Choice,
  type HistoricalConflict,
  type Resolution,
} from '../fixtures/conflicts'
import { emptyGrid, type Grid } from '../fixtures/marks'
import type { Criterion } from '../fixtures/rubrics'
import {
  abilityOf,
  agreedResolution,
  canRefer,
  chosenMark,
  directResolution,
  isValidChoice,
  isValidNote,
  MAX_PROPOSALS,
  toHistory,
  type Resolver,
} from '../lib/conflicts'
import { localDatabaseFor } from '../lib/localDatabase'
import { createLocalAssessmentRecord } from '../lib/localAssessment'
import { changedGridCells, gridFromMarkRows, mergePendingMarkCells, pendingMarkRecord, type GridCellChange } from '../lib/localMarks'
import { markIdFor } from '../lib/markIdentity'
import { mapSyncedAssessmentState, type SyncedAssessmentState } from '../lib/syncedAssessmentState'
import type { SchoolDirectoryState } from './useSchoolDirectory'

/* Everything the screens mutate, held in one place for the life of the session so
   a change made on one screen is still there after navigating away and back. It is
   seeded from fixtures and lives in memory; the IndexedDB store with its outbox
   replaces this hook without changing what the screens receive.

   One reducer rather than a useState per record type: a resolution touches marks,
   history, and the active list together, and must see the same state for all three.
   Each action checks the record it acts on still exists, so a repeated action is a
   no-op rather than a duplicate. Not a state library. */

type State = {
  assessments: Assessment[]
  marks: Record<string, Grid>
  conflicts: ActiveConflict[]
  history: HistoricalConflict[]
  criteriaBySubject: Record<string, Criterion[]>
  resultRecords: SyncedAssessmentState['resultRecords']
}

const emptyState: State = {
  assessments: [],
  marks: {},
  conflicts: [],
  history: [],
  criteriaBySubject: {},
  resultRecords: [],
}

type Action =
  | { type: 'hydrate'; state: SyncedAssessmentState; dirtyConflictIds: string[] }
  | { type: 'addAssessments'; drafts: Omit<Assessment, 'id' | 'version'>[]; ids?: string[] }
  | { type: 'finalizeAssessment'; id: string }
  | { type: 'updateGrid'; assessmentId: string; update: (grid: Grid) => Grid; emptyGrid?: Grid }
  | { type: 'patchGrid'; assessmentId: string; changes: GridCellChange[]; emptyGrid: Grid }
  /* The three ways a conflict moves under ADR 0002. Each is checked against what
     the user may do; an action the user may not take leaves the state as it was. */
  | { type: 'resolveConflict'; id: string; choice: Choice; note: string; user: Resolver; at: string }
  | { type: 'proposeResolution'; id: string; choice: Choice; note: string; user: Resolver; at: string }
  | { type: 'acceptProposal'; id: string; user: Resolver; at: string }
  | { type: 'referConflict'; id: string; user: Resolver; at: string }

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
/* The subject of the assessment a conflict is on; empty when unknown, which grants no moderation. */
function subjectOf(state: State, conflict: ActiveConflict): string {
  return state.assessments.find((x) => x.id === conflict.assessmentId)?.subject ?? ''
}

function criterionMax(state: State, conflict: ActiveConflict): number {
  const a = state.assessments.find((x) => x.id === conflict.assessmentId)
  return a ? (state.criteriaBySubject[a.subject]?.find((c) => c.id === conflict.criterionId)?.max ?? 0) : 0
}

export function reduce(state: State, action: Action): State {
  switch (action.type) {
    case 'hydrate':
      return mergeSyncedState(action.state, state, new Set(action.dirtyConflictIds))

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

    /* Direct settlement: the user's own two-device edits, or a moderator. */
    case 'resolveConflict': {
      const conflict = state.conflicts.find((k) => k.id === action.id)
      if (!conflict) return state
      const ability = abilityOf(conflict, action.user, subjectOf(state, conflict))
      if (ability.kind !== 'resolve') return state
      if (ability.noteRequired && !isValidNote(action.note)) return state
      if (!isValidChoice(conflict, action.choice, criterionMax(state, conflict))) return state
      return settle(state, conflict, directResolution(conflict, action.choice, action.user, action.note), action.at)
    }

    /* A party puts forward a resolution with a note; nothing is applied yet. A
       counter is a second proposal; there is no third: when the rounds are used up
       the conflict is referred as it happens. */
    case 'proposeResolution': {
      const conflict = state.conflicts.find((k) => k.id === action.id)
      if (!conflict) return state
      const ability = abilityOf(conflict, action.user, subjectOf(state, conflict))
      const may = ability.kind === 'propose' || (ability.kind === 'respond' && ability.canCounter)
      if (!may || !isValidNote(action.note)) return state
      if (!isValidChoice(conflict, action.choice, criterionMax(state, conflict))) return state
      const proposal = { byId: action.user.id, by: action.user.name, choice: action.choice, note: action.note, at: action.at }
      const proposals = [...conflict.proposals, proposal]
      return {
        ...state,
        conflicts: state.conflicts.map((k) => (k.id === action.id ? { ...k, proposals } : k)),
      }
    }

    /* A party hands it to a moderator, by choice or because the rounds ran out. */
    case 'referConflict': {
      const conflict = state.conflicts.find((k) => k.id === action.id)
      if (!conflict || !canRefer(conflict, action.user)) return state
      const referral =
        conflict.proposals.length >= MAX_PROPOSALS
          ? { reason: 'rounds' as const, at: action.at }
          : { reason: 'party' as const, byId: action.user.id, by: action.user.name, at: action.at }
      return {
        ...state,
        conflicts: state.conflicts.map((k) => (k.id === action.id ? { ...k, referral } : k)),
      }
    }

    /* The other party accepts: applied now, recorded with both names. */
    case 'acceptProposal': {
      const conflict = state.conflicts.find((k) => k.id === action.id)
      if (!conflict) return state
      const ability = abilityOf(conflict, action.user, subjectOf(state, conflict))
      if (ability.kind !== 'respond') return state
      /* Stored proposals were validated on entry; checked again so the settlement
         path cannot throw on a record that arrived by sync. */
      if (!isValidChoice(conflict, ability.proposal.choice, criterionMax(state, conflict))) return state
      return settle(state, conflict, agreedResolution(ability.proposal, action.user), action.at)
    }
  }
}

/* Applying a resolution: the cell takes the chosen mark (local, queued, per ADR
   0001), the decision goes to history, and the conflict leaves the active list, in
   one transition. Shared by every path that settles a conflict. */
function settle(state: State, conflict: ActiveConflict, resolution: Resolution, at: string): State {
  const choice = resolution.kind === 'auto' ? null : resolution.choice
  const by =
    resolution.kind === 'agreed' ? resolution.acceptedBy
    : resolution.kind === 'auto' ? conflict.mine.who
    : resolution.by
  const { mark, author } = choice
    ? chosenMark(conflict, choice, by)
    : { mark: conflict.mine.mark, author: conflict.mine.who }
  const grid = gridOf(state, conflict.assessmentId)
  return {
    ...state,
    marks: {
      ...state.marks,
      [conflict.assessmentId]: {
        ...grid,
        [conflict.studentId]: {
          ...grid[conflict.studentId],
          [conflict.criterionId]: { mark, sync: 'local', author },
        },
      },
    },
    history: [toHistory(conflict, resolution, at), ...state.history],
    conflicts: state.conflicts.filter((k) => k.id !== conflict.id),
  }
}

/* Who is acting: the signed-in account (build plan 1.7, #45), passed in
   rather than read from Context here, since the caller (App.tsx) already
   has it directly and every dispatch below needs the exact same Resolver
   ConflictActions used to decide what the UI showed. */
export function mergeSyncedState(remote: State, current: State, dirtyConflictIds: ReadonlySet<string>): State {
  const preservedAssessments = current.assessments.filter((assessment) =>
    assessment.version === 0 || assessment.sync === 'pending',
  )
  const preservedAssessmentIds = new Set(preservedAssessments.map((assessment) => assessment.id))
  const assessments = [
    ...preservedAssessments,
    ...remote.assessments.filter((assessment) => !preservedAssessmentIds.has(assessment.id)),
  ]
  const marks = { ...remote.marks }

  for (const [assessmentId, localGrid] of Object.entries(current.marks)) {
    for (const [studentId, localRow] of Object.entries(localGrid)) {
      for (const [criterionId, cell] of Object.entries(localRow)) {
        if (cell.sync !== 'local') continue
        marks[assessmentId] ??= {}
        marks[assessmentId][studentId] ??= {}
        marks[assessmentId][studentId][criterionId] = cell
      }
    }
  }

  const conflicts = [
    ...remote.conflicts.filter((conflict) => !dirtyConflictIds.has(conflict.id)),
    ...current.conflicts.filter((conflict) => dirtyConflictIds.has(conflict.id)),
  ]
  const historyById = new Map(remote.history.map((conflict) => [conflict.id, conflict]))
  for (const conflict of current.history) historyById.set(conflict.id, conflict)

  return { ...remote, assessments, marks, conflicts, history: [...historyById.values()] }
}

export function useSessionStore(user: Resolver, directory: SchoolDirectoryState) {
  const database = localDatabaseFor(user.id)
  const [state, dispatch] = useReducer(reduce, emptyState)
  const stateRef = useRef(state)
  const [hasHydrated, setHasHydrated] = useState(false)
  const dirtyConflictIds = useRef(new Set<string>())
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
      [database.assessments, database.marks, database.conflicts, database.results],
      async () => {
        const [assessments, marks, conflicts, results] = await Promise.all([
          database.assessments.toArray(),
          database.marks.toArray(),
          database.conflicts.toArray(),
          database.results.toArray(),
        ])
        return mapSyncedAssessmentState({ assessments, marks, conflicts, results, directory: directory.data, userId: user.id })
      },
    )).subscribe({
      next: (remote) => {
        commit({ type: 'hydrate', state: remote, dirtyConflictIds: [...dirtyConflictIds.current] })
        setHasHydrated(true)
      },
      error: () => setHasHydrated(true),
    })

    return () => subscription.unsubscribe()
  }, [database, directory, user.id])

  return {
    ready,
    assessments: state.assessments,
    marks: state.marks,
    conflicts: state.conflicts,
    history: state.history,
    criteriaBySubject: state.criteriaBySubject,
    resultRecords: state.resultRecords,

    addAssessments: async (drafts: Omit<Assessment, 'id' | 'version'>[]) => {
      if (!drafts.length) return
      if (directory.status !== 'ready') throw new Error('School data is unavailable on this device')

      const records = drafts.map((draft) => createLocalAssessmentRecord(draft, crypto.randomUUID(), directory.data))
      await database.transaction('rw', database.assessments, () => database.assessments.bulkAdd(records))
      commit({ type: 'addAssessments', drafts, ids: records.map((record) => record.id) })
    },
    finalizeAssessment: async (id: string) => {
      const assessment = state.assessments.find((item) => item.id === id)
      if (!assessment) throw new Error('Assessment is not available on this device')
      if (assessment.status === 'finalized' || assessment.status === 'reports-generated') return

      const finalizedAt = new Date().toISOString()
      const saved = await database.transaction('rw', database.assessments, async () => {
        const current = await database.assessments.get(id)
        if (!current || current.deletedAt != null) return false
        if (current.status === 'finalized' || current.status === 'reports-generated') return false

        const pendingFields = typeof current.pendingFields === 'object' && current.pendingFields !== null && !Array.isArray(current.pendingFields)
          ? current.pendingFields as Record<string, unknown>
          : {}
        const finalization = { finalizedBy: user.id, finalizedAt }
        const isUncreated = current.version === 0

        await database.assessments.update(id, {
          status: 'finalized',
          finalizedBy: user.id,
          finalizedAt,
          sync: 'pending',
          pendingBaseVersion: Number.isSafeInteger(current.pendingBaseVersion) ? current.pendingBaseVersion : current.version,
          pendingFields: isUncreated ? pendingFields : { ...pendingFields, status: 'finalized', ...finalization },
          ...(isUncreated ? { pendingFinalize: finalization } : {}),
        })
        return true
      })
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

        const savedCells = await database.transaction('rw', database.marks, async () => {
          const saved: GridCellChange[] = []
          for (const change of identifiedChanges) {
            const current = await database.marks.get(change.id)
            const record = pendingMarkRecord(current, { ...change, assessmentId }, change.id, user.name)
            await database.marks.put(record)
            saved.push({
              studentId: change.studentId,
              criterionId: change.criterionId,
              cell: {
                ...change.cell,
                sync: 'local',
                baseVersion: record.pendingBaseVersion as number,
                author: user.name,
              },
            })
          }
          return saved
        })

        commit({ type: 'patchGrid', assessmentId, changes: savedCells, emptyGrid })
      }

      const operation = markWriteQueue.current.then(persist, persist)
      markWriteQueue.current = operation.then(() => undefined, () => undefined)
      return operation
    },

    resolveConflict: (id: string, choice: Choice, note = '') => {
      dirtyConflictIds.current.add(id)
      commit({ type: 'resolveConflict', id, choice, note, user, at: new Date().toISOString() })
    },
    proposeResolution: (id: string, choice: Choice, note: string) => {
      dirtyConflictIds.current.add(id)
      commit({ type: 'proposeResolution', id, choice, note, user, at: new Date().toISOString() })
    },
    acceptProposal: (id: string) => {
      dirtyConflictIds.current.add(id)
      commit({ type: 'acceptProposal', id, user, at: new Date().toISOString() })
    },
    referConflict: (id: string) => {
      dirtyConflictIds.current.add(id)
      commit({ type: 'referConflict', id, user, at: new Date().toISOString() })
    },
  }
}

export type SessionStore = ReturnType<typeof useSessionStore>

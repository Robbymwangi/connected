import { useReducer } from 'react'
import { assessments as seedAssessments, type Assessment } from '../fixtures/assessments'
import { classes } from '../fixtures/classes'
import {
  activeConflicts,
  resolvedConflicts,
  type ActiveConflict,
  type Choice,
  type HistoricalConflict,
  type Resolution,
} from '../fixtures/conflicts'
import { emptyGrid, marksByAssessment, type Grid } from '../fixtures/marks'
import { rubricFor } from '../fixtures/rubrics'
import { rosterFor } from '../fixtures/students'
import { currentUser } from '../fixtures/user'
import {
  abilityOf,
  agreedResolution,
  canRefer,
  chosenMark,
  directResolution,
  isValidChoice,
  MAX_PROPOSALS,
  toHistory,
  type Resolver,
} from '../lib/conflicts'

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
}

type Action =
  | { type: 'addAssessments'; drafts: Omit<Assessment, 'id' | 'version'>[] }
  | { type: 'finalizeAssessment'; id: string }
  | { type: 'updateGrid'; assessmentId: string; update: (grid: Grid) => Grid }
  /* The three ways a conflict moves under ADR 0002. Each is checked against what
     the user may do; an action the user may not take leaves the state as it was. */
  | { type: 'resolveConflict'; id: string; choice: Choice; note: string; user: Resolver; at: string }
  | { type: 'proposeResolution'; id: string; choice: Choice; note: string; user: Resolver; at: string }
  | { type: 'acceptProposal'; id: string; user: Resolver; at: string }
  | { type: 'referConflict'; id: string; user: Resolver; at: string }

export const seed: State = {
  assessments: seedAssessments,
  marks: marksByAssessment,
  conflicts: activeConflicts,
  history: resolvedConflicts,
}

function emptyGridFor(state: State, assessmentId: string): Grid {
  const a = state.assessments.find((x) => x.id === assessmentId)
  const cls = a && classes.find((c) => c.stream === a.stream)
  return emptyGrid(
    rosterFor(cls?.id ?? '').map((s) => s.id),
    a ? rubricFor(a.subject).map((c) => c.id) : [],
  )
}

function gridOf(state: State, assessmentId: string): Grid {
  return state.marks[assessmentId] ?? emptyGridFor(state, assessmentId)
}

/* The maximum for the criterion a conflict is about; 0 when unknown, which makes
   every corrected score invalid rather than accepting one blindly. */
function criterionMax(state: State, conflict: ActiveConflict): number {
  const a = state.assessments.find((x) => x.id === conflict.assessmentId)
  return a ? (rubricFor(a.subject).find((c) => c.id === conflict.criterionId)?.max ?? 0) : 0
}

export function reduce(state: State, action: Action): State {
  switch (action.type) {
    /* Primary keys are client-generated UUIDs, assigned here at creation; a record
       made offline cannot wait for a server to number it. Version 0 means the server
       has never acknowledged it (ADR 0001). */
    case 'addAssessments':
      return {
        ...state,
        assessments: [
          ...action.drafts.map((d) => ({ ...d, id: crypto.randomUUID(), version: 0 })),
          ...state.assessments,
        ],
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
        marks: { ...state.marks, [action.assessmentId]: action.update(gridOf(state, action.assessmentId)) },
      }

    /* Direct settlement: the user's own two-device edits, or a moderator. */
    case 'resolveConflict': {
      const conflict = state.conflicts.find((k) => k.id === action.id)
      if (!conflict) return state
      const ability = abilityOf(conflict, action.user)
      if (ability.kind !== 'resolve') return state
      if (ability.noteRequired && action.note.trim() === '') return state
      if (!isValidChoice(conflict, action.choice, criterionMax(state, conflict))) return state
      return settle(state, conflict, directResolution(conflict, action.choice, action.user, action.note), action.at)
    }

    /* A party puts forward a resolution with a note; nothing is applied yet. A
       counter is a second proposal; there is no third: when the rounds are used up
       the conflict is referred as it happens. */
    case 'proposeResolution': {
      const conflict = state.conflicts.find((k) => k.id === action.id)
      if (!conflict) return state
      const ability = abilityOf(conflict, action.user)
      const may = ability.kind === 'propose' || (ability.kind === 'respond' && ability.canCounter)
      if (!may || action.note.trim() === '') return state
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
      const ability = abilityOf(conflict, action.user)
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

/* Who is acting. Comes from authentication later. */
const user: Resolver = { id: currentUser.id, name: currentUser.fullName, canModerate: currentUser.canModerate }

export function useSessionStore() {
  const [state, dispatch] = useReducer(reduce, seed)

  return {
    assessments: state.assessments,
    marks: state.marks,
    conflicts: state.conflicts,
    history: state.history,

    addAssessments: (drafts: Omit<Assessment, 'id' | 'version'>[]) =>
      dispatch({ type: 'addAssessments', drafts }),
    finalizeAssessment: (id: string) => dispatch({ type: 'finalizeAssessment', id }),

    gridFor: (assessmentId: string): Grid => gridOf(state, assessmentId),
    updateGrid: (assessmentId: string, update: (grid: Grid) => Grid) =>
      dispatch({ type: 'updateGrid', assessmentId, update }),

    resolveConflict: (id: string, choice: Choice, note = '') =>
      dispatch({ type: 'resolveConflict', id, choice, note, user, at: new Date().toISOString() }),
    proposeResolution: (id: string, choice: Choice, note: string) =>
      dispatch({ type: 'proposeResolution', id, choice, note, user, at: new Date().toISOString() }),
    acceptProposal: (id: string) =>
      dispatch({ type: 'acceptProposal', id, user, at: new Date().toISOString() }),
    referConflict: (id: string) =>
      dispatch({ type: 'referConflict', id, user, at: new Date().toISOString() }),
  }
}

export type SessionStore = ReturnType<typeof useSessionStore>

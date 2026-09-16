import type {
  ActiveConflict,
  Choice,
  ConflictSide,
  HistoricalConflict,
  Proposal,
  Referral,
  Resolution,
} from '../fixtures/conflicts'
import type { Mark } from './grading'

/* Settling a conflict under ADR 0002. Pure: the store applies what these return. */

export type Resolver = { id: string; name: string; canModerate: boolean }

export function marksEqual(a: Mark, b: Mark): boolean {
  if (a.kind !== b.kind) return false
  return a.kind === 'score' && b.kind === 'score' ? a.value === b.value : true
}

/* The sync engine may settle this without a person only when there is nothing to
   decide. */
export function isAutoResolvable(conflict: ActiveConflict): boolean {
  return marksEqual(conflict.mine.mark, conflict.theirs.mark)
}

/* One teacher's own edits from two devices: no second party. */
export function isSelfConflict(conflict: ActiveConflict): boolean {
  return conflict.mine.userId === conflict.theirs.userId
}

/* One proposal each, then it is referred (ADR 0002 rule 4). */
export const MAX_PROPOSALS = 2

export function pendingProposal(conflict: ActiveConflict): Proposal | undefined {
  return conflict.proposals[conflict.proposals.length - 1]
}

/* What this user may do with this conflict.
   resolve:  settle it directly (own edits, or a moderator; the latter with a note).
   propose:  put forward a resolution with a note for the other party.
   respond:  a proposal from the other party is waiting: accept it, counter it if a
             round remains, or refer it.
   awaiting: this user's own proposal is waiting for the other party.
   referred: with a moderator; parties can only watch. */
export type Ability =
  | { kind: 'resolve'; noteRequired: boolean }
  | { kind: 'propose' }
  | { kind: 'respond'; proposal: Proposal; canCounter: boolean }
  | { kind: 'awaiting'; proposal: Proposal }
  | { kind: 'referred'; referral: Referral }

export function abilityOf(conflict: ActiveConflict, user: Resolver): Ability {
  if (isSelfConflict(conflict)) return { kind: 'resolve', noteRequired: false }
  if (user.canModerate) return { kind: 'resolve', noteRequired: true }
  if (conflict.referral) return { kind: 'referred', referral: conflict.referral }
  const proposal = pendingProposal(conflict)
  if (!proposal) return { kind: 'propose' }
  if (proposal.byId === user.id) return { kind: 'awaiting', proposal }
  return { kind: 'respond', proposal, canCounter: conflict.proposals.length < MAX_PROPOSALS }
}

/* Whether a party may refer this to a moderator: any time before it is settled or
   already referred, and never for their own two-device edits. */
export function canRefer(conflict: ActiveConflict, user: Resolver): boolean {
  return !isSelfConflict(conflict) && !user.canModerate && !conflict.referral
}

/* The side a choice names, on whichever device it is read. */
export function sideOf(conflict: ActiveConflict | HistoricalConflict, editId: string): ConflictSide | undefined {
  return [conflict.mine, conflict.theirs].find((s) => s.editId === editId)
}

/* The mark the cell ends up holding, and whose it is. */
export function chosenMark(
  conflict: ActiveConflict,
  choice: Choice,
  by: string,
): { mark: Mark; author: string } {
  if (choice.kind === 'corrected') return { mark: choice.mark, author: by }
  const side = sideOf(conflict, choice.editId)
  if (!side) throw new RangeError(`chosenMark: edit ${choice.editId} is not a side of conflict ${conflict.id}`)
  return { mark: side.mark, author: side.who }
}

/* The resolution a direct settlement produces: self for own edits, moderated
   otherwise. */
export function directResolution(
  conflict: ActiveConflict,
  choice: Choice,
  by: Resolver,
  note: string,
): Resolution {
  return isSelfConflict(conflict)
    ? { kind: 'self', byId: by.id, by: by.name, choice }
    : { kind: 'moderated', byId: by.id, by: by.name, choice, note }
}

/* The resolution an acceptance produces. */
export function agreedResolution(proposal: Proposal, acceptedBy: Resolver): Resolution {
  return {
    kind: 'agreed',
    proposedById: proposal.byId,
    proposedBy: proposal.by,
    acceptedById: acceptedBy.id,
    acceptedBy: acceptedBy.name,
    choice: proposal.choice,
    note: proposal.note,
  }
}

/* Which mark a resolution settled on, for the audit record. */
export function resolutionChoice(resolution: Resolution): Choice | null {
  return resolution.kind === 'auto' ? null : resolution.choice
}

export function toHistory(
  conflict: ActiveConflict,
  resolution: Resolution,
  at: string,
): HistoricalConflict {
  const { baseVersion: _base, ...record } = conflict
  return { ...record, resolution, resolvedAt: at }
}

/* The one line shown for a referral, in the active card and in history. */
export function describeReferral(referral: Referral): string {
  return referral.reason === 'rounds'
    ? 'Referred to a moderator: one proposal each, no agreement.'
    : `Referred to a moderator by ${referral.by}.`
}

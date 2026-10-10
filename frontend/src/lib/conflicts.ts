import type {
  ActiveConflict,
  Choice,
  ConflictSide,
  HistoricalConflict,
  Proposal,
  Referral,
  Resolution,
} from '../fixtures/conflicts'
import type { ConflictCommand } from './conflictCommands'
import type { Mark } from './grading'

/* Settling a conflict under ADR 0002. Pure: the store applies what these return. */

/* moderatedSubjects: the ids of the subjects this user moderates, because moderation is granted one subject at
   a time (docs/spec/access-model.md); a conflict is judged against its own assessment's subject id. */
export type Resolver = { id: string; name: string; moderatedSubjects: readonly string[] }

export function moderates(user: Resolver, subjectId: string): boolean {
  return user.moderatedSubjects.includes(subjectId)
}

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

/* What this user may do with this conflict. A party acts only as a party, even one who also moderates the
   subject: neither party may resolve alone, and a moderator's whole value is being impartial (ADR 0002,
   2026-10-07 amendment). The server applies the same table, and conflicts.policy.test.ts holds both to it.
   resolve:  settle it directly (own edits, or a moderator who is not a party; the latter with a note).
   propose:  put forward a resolution with a note for the other party.
   respond:  a proposal from the other party is waiting: accept it, counter it if a
             round remains, or refer it.
   awaiting: this user's own proposal is waiting for the other party.
   referred: with a moderator; parties can only watch.
   observer: neither a party nor a moderator; can only watch. */
export type Ability =
  | { kind: 'resolve'; noteRequired: boolean }
  | { kind: 'propose' }
  | { kind: 'respond'; proposal: Proposal; canCounter: boolean }
  | { kind: 'awaiting'; proposal: Proposal }
  | { kind: 'referred'; referral: Referral }
  | { kind: 'observer' }

export function isParty(conflict: ActiveConflict, user: Resolver): boolean {
  return conflict.mine.userId === user.id || conflict.theirs.userId === user.id
}

export function abilityOf(conflict: ActiveConflict, user: Resolver, subjectId: string): Ability {
  if (!isParty(conflict, user)) {
    return moderates(user, subjectId) && !isSelfConflict(conflict) ? { kind: 'resolve', noteRequired: true } : { kind: 'observer' }
  }
  if (isSelfConflict(conflict)) return { kind: 'resolve', noteRequired: false }
  if (conflict.referral) return { kind: 'referred', referral: conflict.referral }
  const proposal = pendingProposal(conflict)
  if (!proposal) return { kind: 'propose' }
  if (proposal.byId === user.id) return { kind: 'awaiting', proposal }
  return { kind: 'respond', proposal, canCounter: conflict.proposals.length < MAX_PROPOSALS }
}

/* Whether a party may refer this to a moderator: any time before it is settled or already referred, and never
   for their own two-device edits. After a proposal and a counter only the original proposer may, so the author
   of the counter waits (ADR 0002 rule 4). A party who moderates the subject may refer too: they are a party. */
export function canRefer(conflict: ActiveConflict, user: Resolver): boolean {
  if (!isParty(conflict, user) || isSelfConflict(conflict) || conflict.referral) return false
  const waitingOnTheOriginalProposer =
    conflict.proposals.length >= MAX_PROPOSALS && pendingProposal(conflict)?.byId === user.id
  return !waitingOnTheOriginalProposer
}

/* A note is required with a proposal and a moderated resolution: not blank, no NUL, at most 2000 characters.
   Counted in code points, as the server counts characters, so the two agree on a note full of emoji. */
export const MAX_NOTE_LENGTH = 2000

export function isValidNote(note: string): boolean {
  return note.trim() !== '' && !note.includes('\0') && [...note].length <= MAX_NOTE_LENGTH
}

/* Whether a choice can be applied to this conflict: a side must be one of its two
   edits; a corrected mark must be a value (not empty) within the criterion's
   maximum. The screens enforce this too, but the store is the boundary. */
export function isValidChoice(conflict: ActiveConflict, choice: Choice, max: number): boolean {
  if (choice.kind === 'side') return sideOf(conflict, choice.editId) !== undefined
  const { mark } = choice
  if (mark.kind === 'empty') return false
  if (mark.kind === 'absent') return true
  return Number.isInteger(mark.value) && mark.value >= 0 && mark.value <= max
}

/* The side a choice names, on whichever device it is read. */
export function sideOf(conflict: ActiveConflict | HistoricalConflict, editId: string): ConflictSide | undefined {
  return [conflict.mine, conflict.theirs].find((s) => s.editId === editId)
}

/* Which mark a resolution settled on, for the audit record. */
export function resolutionChoice(resolution: Resolution): Choice | null {
  return resolution.kind === 'auto' ? null : resolution.choice
}

/* The one line shown for a referral, in the active card and in history. */
export function describeReferral(referral: Referral): string {
  return referral.reason === 'rounds'
    ? 'Referred to a moderator: one proposal each, no agreement.'
    : `Referred to a moderator by ${referral.by}.`
}

/* What a teacher asks to do to a conflict, before the policy has had its say. */
export type CommandRequest =
  | { action: 'propose'; choice: Choice; note: string }
  | { action: 'refer' }
  | { action: 'accept' }
  | { action: 'resolve'; choice: Choice; note: string }

/* The command a request amounts to, or null when this user may not do it. This is the
   one place the policy (ADR 0002, and the table in docs/spec/sync-protocol.md) decides
   whether to enqueue anything; the server applies the same table and answers anything
   that slipped past. `max` is the criterion's current maximum, so a corrected mark, or a
   proposal being accepted, that no longer fits is refused here and not sent. */
export function planCommand(
  conflict: ActiveConflict,
  request: CommandRequest,
  user: Resolver,
  max: number,
): ConflictCommand | null {
  const ability = abilityOf(conflict, user, conflict.subjectId)

  switch (request.action) {
    case 'resolve': {
      if (ability.kind !== 'resolve') return null
      const note = request.note.trim()
      if (ability.noteRequired && !isValidNote(note)) return null
      if (!isValidChoice(conflict, request.choice, max)) return null
      return isSelfConflict(conflict)
        ? { kind: 'resolve', resolution: 'self', byId: user.id, choice: request.choice }
        : { kind: 'resolve', resolution: 'moderated', byId: user.id, choice: request.choice, note }
    }

    case 'propose': {
      const may = ability.kind === 'propose' || (ability.kind === 'respond' && ability.canCounter)
      const note = request.note.trim()
      if (!may || !isValidNote(note) || !isValidChoice(conflict, request.choice, max)) return null
      return { kind: 'propose', byId: user.id, choice: request.choice, note }
    }

    case 'refer':
      return canRefer(conflict, user) ? { kind: 'refer', byId: user.id } : null

    case 'accept': {
      if (ability.kind !== 'respond') return null
      /* A stored proposal was valid when made; checked again, since the maximum may
         have changed or the proposal may have arrived by sync. */
      if (!isValidChoice(conflict, ability.proposal.choice, max)) return null
      return { kind: 'accept', proposedById: ability.proposal.byId, acceptedById: user.id }
    }
  }
}

/* The one definition of what a conflict means to the person looking at it, used by the
   top bar, the dashboard, and the Sync screen alike so they cannot disagree.
   needsYou: they can act on it now (settle it, put forward a resolution, or answer one).
   waiting:  theirs, but only another person can move it (their proposal is pending, or it
             is with a moderator and they are not the moderator).
   others:   neither a party nor a moderator of the subject. Anyone in the school may read
             these (docs/spec/access-model.md); they are never counted. */
export type ConflictGroups = { needsYou: ActiveConflict[]; waiting: ActiveConflict[]; others: ActiveConflict[] }

export function groupConflicts(conflicts: readonly ActiveConflict[], user: Resolver): ConflictGroups {
  const groups: ConflictGroups = { needsYou: [], waiting: [], others: [] }
  for (const conflict of conflicts) {
    const { kind } = abilityOf(conflict, user, conflict.subjectId)
    if (kind === 'resolve' || kind === 'propose' || kind === 'respond') groups.needsYou.push(conflict)
    else if (kind === 'awaiting' || kind === 'referred') groups.waiting.push(conflict)
    else groups.others.push(conflict)
  }
  return groups
}

const countOf = (count: number) => `${count} ${count === 1 ? 'conflict' : 'conflicts'}`

/* The line under the Sync heading. */
export function describeConflictCounts({ needsYou, waiting }: ConflictGroups): string {
  if (needsYou.length > 0) {
    return waiting.length > 0
      ? `${countOf(needsYou.length)} to resolve, ${waiting.length} waiting on others`
      : `${countOf(needsYou.length)} to resolve`
  }
  return waiting.length > 0 ? `Nothing for you to do; ${waiting.length} waiting on others` : 'All conflicts resolved'
}

/* The heading when the last read of the saved data may have failed. A failed read keeps
   what was last read on screen, which is better than nothing and worse than the truth, so
   it is labelled; and with nothing of theirs to show, "all clear" would be a claim nobody
   checked. */
export function describeConflictHeading(groups: ConflictGroups, loadFailed: boolean): string {
  if (!loadFailed) return describeConflictCounts(groups)
  if (groups.needsYou.length + groups.waiting.length === 0) return 'Conflicts could not be read'
  return `${describeConflictCounts(groups)} (as last read; could not refresh)`
}

/* Why the Finalize control is off, or null when it is on. Any open conflict on the
   assessment blocks it, whoever's it is, because the server refuses either way. */
export function describeFinalizeBlock(open: number): string | null {
  if (open === 0) return null
  return open === 1
    ? '1 conflict is open on this assessment. Settle it on the cell marked conflict before finalizing.'
    : `${open} conflicts are open on this assessment. Settle them on the cells marked conflict before finalizing.`
}

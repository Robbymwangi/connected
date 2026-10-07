import { describe, expect, it } from 'vitest'
import type { ActiveConflict, Proposal } from '../fixtures/conflicts'
import {
  abilityOf,
  agreedResolution,
  canRefer,
  isValidChoice,
  chosenMark,
  directResolution,
  isAutoResolvable,
  isSelfConflict,
  isValidNote,
  marksEqual,
  toHistory,
} from './conflicts'
import { ABSENT, EMPTY, score } from './grading'

const SUBJECT = 'English'
const me = { id: 'u-1', name: 'John Doe', moderatedSubjects: [] as string[] }
const hod = { id: 'u-3', name: 'Mr. Kamau', moderatedSubjects: [SUBJECT] }

const crossTeacher: ActiveConflict = {
  id: 'c',
  assessmentId: 'a1',
  baseVersion: 6,
  studentId: 's1',
  criterionId: 'c3',
  student: 'Wanjiku Njoroge',
  criterion: 'Oral Fluency',
  assessment: 'English CAT 2, Term 2',
  mine: { editId: 'e-m', userId: 'u-1', who: 'John Doe', mark: score(14), at: '2026-08-27T14:32:00' },
  theirs: { editId: 'e-t', userId: 'u-2', who: 'Ms. Akinyi', mark: score(11), at: '2026-08-27T16:05:00' },
  proposals: [],
}
const ownEdits: ActiveConflict = {
  ...crossTeacher,
  theirs: { editId: 'e-t', userId: 'u-1', who: 'John Doe', mark: score(12), at: '2026-08-27T15:40:00' },
}
const proposalByHer: Proposal = {
  byId: 'u-2',
  by: 'Ms. Akinyi',
  choice: { kind: 'side', editId: 'e-t' },
  note: 're-marked',
  at: '2026-08-27T17:00:00',
}

describe('marksEqual and isAutoResolvable', () => {
  it('compares scores by value and other kinds by kind', () => {
    expect(marksEqual(score(9), score(9))).toBe(true)
    expect(marksEqual(score(9), score(10))).toBe(false)
    expect(marksEqual(ABSENT, ABSENT)).toBe(true)
    expect(marksEqual(ABSENT, EMPTY)).toBe(false)
  })
  it('auto-resolves only when both sides already agree', () => {
    expect(isAutoResolvable(crossTeacher)).toBe(false)
    expect(isAutoResolvable({ ...crossTeacher, theirs: { ...crossTeacher.theirs, mark: score(14) } })).toBe(true)
  })
})

describe('abilityOf', () => {
  it('lets a teacher settle their own two-device edits directly, no note needed', () => {
    expect(isSelfConflict(ownEdits)).toBe(true)
    expect(abilityOf(ownEdits, me, SUBJECT)).toEqual({ kind: 'resolve', noteRequired: false })
  })
  it('never lets a party settle a cross-teacher conflict alone', () => {
    expect(abilityOf(crossTeacher, me, SUBJECT)).toEqual({ kind: 'propose' })
  })
  it('lets a moderator settle it outright, with a note', () => {
    expect(abilityOf(crossTeacher, hod, SUBJECT)).toEqual({ kind: 'resolve', noteRequired: true })
  })
  it('asks the other party to respond to a pending proposal, with a counter still open', () => {
    expect(abilityOf({ ...crossTeacher, proposals: [proposalByHer] }, me, SUBJECT)).toEqual({ kind: 'respond', proposal: proposalByHer, canCounter: true })
  })
  it('makes the proposer wait for the other party', () => {
    const mine: Proposal = { ...proposalByHer, byId: 'u-1', by: 'John Doe' }
    expect(abilityOf({ ...crossTeacher, proposals: [mine] }, me, SUBJECT)).toEqual({ kind: 'awaiting', proposal: mine })
  })
  it('after one proposal each, the response can only be accept or refer', () => {
    const mine: Proposal = { ...proposalByHer, byId: 'u-1', by: 'John Doe' }
    const twoRounds = { ...crossTeacher, proposals: [mine, proposalByHer] }
    expect(abilityOf(twoRounds, me, SUBJECT)).toEqual({ kind: 'respond', proposal: proposalByHer, canCounter: false })
  })
  it('a referred conflict is out of the parties\' hands but not the moderator\'s', () => {
    const referred = { ...crossTeacher, referral: { reason: 'rounds' as const, at: '2026-08-28T09:00:00' } }
    expect(abilityOf(referred, me, SUBJECT)).toEqual({ kind: 'referred', referral: referred.referral })
    expect(abilityOf(referred, hod, SUBJECT)).toEqual({ kind: 'resolve', noteRequired: true })
  })
  it('someone who is neither a party nor a moderator can only observe', () => {
    const other = { id: 'u-9', name: 'Mr. Otieno', moderatedSubjects: [] as string[] }
    expect(abilityOf(crossTeacher, other, SUBJECT)).toEqual({ kind: 'observer' })
    expect(abilityOf({ ...crossTeacher, proposals: [proposalByHer] }, other, SUBJECT)).toEqual({ kind: 'observer' })
    expect(canRefer(crossTeacher, other)).toBe(false)
  })
  it('a party may refer any cross-teacher conflict that is not already referred', () => {
    expect(canRefer(crossTeacher, me)).toBe(true)
    expect(canRefer(ownEdits, me)).toBe(false)
    expect(canRefer(crossTeacher, hod)).toBe(false)
    expect(canRefer({ ...crossTeacher, referral: { reason: 'party', byId: 'u-2', by: 'Ms. Akinyi', at: 'x' } }, me)).toBe(false)
  })
  it('moderation is per subject: a moderator of another subject only observes', () => {
    const maths = { id: 'u-3', name: 'Mr. Kamau', moderatedSubjects: ['Maths'] }
    expect(abilityOf(crossTeacher, maths, SUBJECT)).toEqual({ kind: 'observer' })
    expect(abilityOf(crossTeacher, maths, 'Maths')).toEqual({ kind: 'resolve', noteRequired: true })
  })
  it('a moderator who is also a party acts as a party: proposes, cannot resolve, may refer', () => {
    const partyAndHod = { id: 'u-1', name: 'John Doe', moderatedSubjects: [SUBJECT] }
    expect(abilityOf(crossTeacher, partyAndHod, SUBJECT)).toEqual({ kind: 'propose' })
    expect(canRefer(crossTeacher, partyAndHod)).toBe(true)
  })
  it('a moderator of someone else\'s own two-device edits cannot settle them', () => {
    expect(abilityOf(ownEdits, hod, SUBJECT)).toEqual({ kind: 'observer' })
  })
  it('after a proposal and a counter only the original proposer may accept or refer', () => {
    const mine: Proposal = { ...proposalByHer, byId: 'u-1', by: 'John Doe', choice: { kind: 'side', editId: 'e-m' } }
    const counter: Proposal = { ...proposalByHer, byId: 'u-2' }
    const twoRounds = { ...crossTeacher, proposals: [mine, counter] }
    const her = { id: 'u-2', name: 'Ms. Akinyi', moderatedSubjects: [] as string[] }
    expect(abilityOf(twoRounds, her, SUBJECT).kind).toBe('awaiting')
    expect(canRefer(twoRounds, her)).toBe(false)
    expect(canRefer(twoRounds, me)).toBe(true)
  })
})

describe('chosenMark', () => {
  it('a side keeps that side\'s mark and author', () => {
    expect(chosenMark(crossTeacher, { kind: 'side', editId: 'e-t' }, 'John Doe')).toEqual({ mark: score(11), author: 'Ms. Akinyi' })
    expect(() => chosenMark(crossTeacher, { kind: 'side', editId: 'nope' }, 'John Doe')).toThrow(RangeError)
  })
  it('a correction is authored by whoever settled it', () => {
    expect(chosenMark(crossTeacher, { kind: 'corrected', mark: ABSENT }, 'Mr. Kamau')).toEqual({ mark: ABSENT, author: 'Mr. Kamau' })
  })
})

describe('isValidChoice', () => {
  it('accepts only the conflict\'s own edits as sides', () => {
    expect(isValidChoice(crossTeacher, { kind: 'side', editId: 'e-m' }, 20)).toBe(true)
    expect(isValidChoice(crossTeacher, { kind: 'side', editId: 'nope' }, 20)).toBe(false)
  })
  it('accepts a corrected score within the maximum, or absent, never empty', () => {
    expect(isValidChoice(crossTeacher, { kind: 'corrected', mark: score(20) }, 20)).toBe(true)
    expect(isValidChoice(crossTeacher, { kind: 'corrected', mark: score(21) }, 20)).toBe(false)
    expect(isValidChoice(crossTeacher, { kind: 'corrected', mark: ABSENT }, 20)).toBe(true)
    expect(isValidChoice(crossTeacher, { kind: 'corrected', mark: EMPTY }, 20)).toBe(false)
  })
})

describe('resolutions', () => {
  it('own edits settle as self, cross-teacher settles as moderated', () => {
    const keepMine = { kind: 'side' as const, editId: 'e-m' }
    expect(directResolution(ownEdits, keepMine, me, '')).toEqual({ kind: 'self', byId: 'u-1', by: 'John Doe', choice: keepMine })
    expect(directResolution(crossTeacher, keepMine, hod, 'moderated')).toEqual({ kind: 'moderated', byId: 'u-3', by: 'Mr. Kamau', choice: keepMine, note: 'moderated' })
  })
  it('an acceptance records both names and the proposer\'s note', () => {
    expect(agreedResolution(proposalByHer, me)).toEqual({ kind: 'agreed', proposedById: 'u-2', proposedBy: 'Ms. Akinyi', acceptedById: 'u-1', acceptedBy: 'John Doe', choice: proposalByHer.choice, note: 're-marked' })
  })
  it('history keeps both sides and drops the working fields', () => {
    const h = toHistory({ ...crossTeacher, proposals: [proposalByHer] }, agreedResolution(proposalByHer, me), '2026-08-28T09:00:00')
    expect(h.mine).toEqual(crossTeacher.mine)
    expect(h.theirs).toEqual(crossTeacher.theirs)
    expect(h.proposals).toEqual([proposalByHer])
    expect('baseVersion' in h).toBe(false)
    expect(h.resolvedAt).toBe('2026-08-28T09:00:00')
  })
})

describe('isValidNote', () => {
  it('needs something other than whitespace, no NUL, and at most 2000 characters', () => {
    expect(isValidNote('re-marked from the script')).toBe(true)
    expect(isValidNote('  \t ')).toBe(false)
    expect(isValidNote('bad\0note')).toBe(false)
    expect(isValidNote('x'.repeat(2000))).toBe(true)
    expect(isValidNote('x'.repeat(2001))).toBe(false)
  })
  it('counts characters, not UTF-16 units, as the server does', () => {
    expect(isValidNote('😀'.repeat(2000))).toBe(true)
    expect(isValidNote('😀'.repeat(2001))).toBe(false)
  })
})

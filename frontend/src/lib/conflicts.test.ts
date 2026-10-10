import { describe, expect, it } from 'vitest'
import type { ActiveConflict, Proposal } from '../fixtures/conflicts'
import {
  abilityOf,
  canRefer,
  describeConflictCounts,
  describeConflictHeading,
  groupConflicts,
  isValidChoice,
  isAutoResolvable,
  isSelfConflict,
  isValidNote,
  marksEqual,
  planCommand,
} from './conflicts'
import { ABSENT, EMPTY, score } from './grading'

const SUBJECT = 'subject-english'
const me = { id: 'u-1', name: 'John Doe', moderatedSubjects: [] as string[] }
const hod = { id: 'u-3', name: 'Mr. Kamau', moderatedSubjects: [SUBJECT] }

const crossTeacher: ActiveConflict = {
  id: 'c',
  assessmentId: 'a1',
  subjectId: SUBJECT,
  baseVersion: 6,
  conflictVersion: 1,
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
    const maths = { id: 'u-3', name: 'Mr. Kamau', moderatedSubjects: ['subject-maths'] }
    expect(abilityOf(crossTeacher, maths, SUBJECT)).toEqual({ kind: 'observer' })
    expect(abilityOf(crossTeacher, maths, 'subject-maths')).toEqual({ kind: 'resolve', noteRequired: true })
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

describe('planCommand', () => {
  const her = { id: 'u-2', name: 'Ms. Akinyi', moderatedSubjects: [] as string[] }
  const mineSide = { kind: 'side' as const, editId: 'e-m' }
  const theirSide = { kind: 'side' as const, editId: 'e-t' }
  const plan = (conflict: ActiveConflict, request: Parameters<typeof planCommand>[1], user = me, max = 20) =>
    planCommand(conflict, request, user, max)

  describe('resolve', () => {
    it('lets a teacher settle their own two-device edits, with no note', () => {
      expect(plan(ownEdits, { action: 'resolve', choice: theirSide, note: '' })).toEqual({
        kind: 'resolve', resolution: 'self', byId: 'u-1', choice: theirSide,
      })
    })

    it('refuses a party settling a cross-teacher conflict alone', () => {
      expect(plan(crossTeacher, { action: 'resolve', choice: theirSide, note: 'please' })).toBeNull()
    })

    it('lets a moderator settle it, but only with a note, which is trimmed', () => {
      expect(plan(crossTeacher, { action: 'resolve', choice: theirSide, note: '  ' }, hod)).toBeNull()
      expect(plan(crossTeacher, { action: 'resolve', choice: theirSide, note: ' moderated ' }, hod)).toEqual({
        kind: 'resolve', resolution: 'moderated', byId: 'u-3', choice: theirSide, note: 'moderated',
      })
    })

    it('judges moderation against the conflict\'s own subject id', () => {
      const elsewhere = { ...crossTeacher, subjectId: 'subject-maths' }

      expect(plan(elsewhere, { action: 'resolve', choice: theirSide, note: 'n' }, hod)).toBeNull()
    })

    it('refuses a side that is not one of the edits, a corrected mark over the maximum, and an empty one', () => {
      const attempt = (choice: Parameters<typeof isValidChoice>[1]) => plan(ownEdits, { action: 'resolve', choice, note: '' })

      expect(attempt({ kind: 'side', editId: 'nope' })).toBeNull()
      expect(attempt({ kind: 'corrected', mark: score(99) })).toBeNull()
      expect(attempt({ kind: 'corrected', mark: EMPTY })).toBeNull()
      expect(attempt({ kind: 'corrected', mark: ABSENT })).not.toBeNull()
    })

    it('a moderator who is also a party acts as a party and cannot resolve', () => {
      const partyAndHod = { id: 'u-1', name: 'John Doe', moderatedSubjects: [SUBJECT] }

      expect(plan(crossTeacher, { action: 'resolve', choice: theirSide, note: 'n' }, partyAndHod)).toBeNull()
    })
  })

  describe('propose', () => {
    it('needs a note and a valid choice', () => {
      expect(plan(crossTeacher, { action: 'propose', choice: theirSide, note: '' })).toBeNull()
      expect(plan(crossTeacher, { action: 'propose', choice: { kind: 'side', editId: 'nope' }, note: 'n' })).toBeNull()
      expect(plan(crossTeacher, { action: 'propose', choice: theirSide, note: ' agreed in moderation ' })).toEqual({
        kind: 'propose', byId: 'u-1', choice: theirSide, note: 'agreed in moderation',
      })
    })

    it('lets the other party counter once, and not a third time', () => {
      const countered = plan({ ...crossTeacher, proposals: [proposalByHer] }, { action: 'propose', choice: mineSide, note: 'split it' })
      expect(countered).toMatchObject({ kind: 'propose' })

      const mine: Proposal = { ...proposalByHer, byId: 'u-1', by: 'John Doe', choice: mineSide }
      const twoRounds = { ...crossTeacher, proposals: [mine, proposalByHer] }
      expect(plan(twoRounds, { action: 'propose', choice: mineSide, note: 'again' })).toBeNull()
    })

    it('does not let the proposer propose again while theirs is waiting, or a non-party propose at all', () => {
      const mine: Proposal = { ...proposalByHer, byId: 'u-1', by: 'John Doe', choice: mineSide }
      const other = { id: 'u-9', name: 'Mr. Otieno', moderatedSubjects: [] as string[] }

      expect(plan({ ...crossTeacher, proposals: [mine] }, { action: 'propose', choice: mineSide, note: 'n' })).toBeNull()
      expect(plan(crossTeacher, { action: 'propose', choice: mineSide, note: 'n' }, other)).toBeNull()
    })
  })

  describe('accept', () => {
    it('lets the other party accept, naming who proposed and who accepted', () => {
      expect(plan({ ...crossTeacher, proposals: [proposalByHer] }, { action: 'accept' })).toEqual({
        kind: 'accept', proposedById: 'u-2', acceptedById: 'u-1',
      })
    })

    it('does not let the proposer accept their own, a non-party accept, or accept when nothing is pending', () => {
      const withHers = { ...crossTeacher, proposals: [proposalByHer] }
      const other = { id: 'u-9', name: 'Mr. Otieno', moderatedSubjects: [] as string[] }

      expect(plan(withHers, { action: 'accept' }, her)).toBeNull()
      expect(plan(withHers, { action: 'accept' }, other)).toBeNull()
      expect(plan(crossTeacher, { action: 'accept' })).toBeNull()
    })

    it('refuses a proposal that can no longer be applied, such as a corrected mark over a lowered maximum', () => {
      const over: Proposal = { ...proposalByHer, choice: { kind: 'corrected', mark: score(18) } }

      expect(plan({ ...crossTeacher, proposals: [over] }, { action: 'accept' }, me, 15)).toBeNull()
      expect(plan({ ...crossTeacher, proposals: [over] }, { action: 'accept' }, me, 20)).not.toBeNull()
    })
  })

  describe('refer', () => {
    it('lets a party refer, but not their own edits, an already referred conflict, or a moderator', () => {
      expect(plan(crossTeacher, { action: 'refer' })).toEqual({ kind: 'refer', byId: 'u-1' })
      expect(plan(ownEdits, { action: 'refer' })).toBeNull()
      expect(plan({ ...crossTeacher, referral: { reason: 'party', byId: 'u-2', by: 'Ms. Akinyi', at: 'x' } }, { action: 'refer' })).toBeNull()
      expect(plan(crossTeacher, { action: 'refer' }, hod)).toBeNull()
    })

    it('after a proposal and a counter, lets only the original proposer refer', () => {
      const mine: Proposal = { ...proposalByHer, byId: 'u-1', by: 'John Doe', choice: mineSide }
      const twoRounds = { ...crossTeacher, proposals: [mine, proposalByHer] }

      expect(plan(twoRounds, { action: 'refer' }, me)).not.toBeNull()
      expect(plan(twoRounds, { action: 'refer' }, her)).toBeNull()
    })
  })
})


describe('groupConflicts', () => {
  const her = { id: 'u-2', name: 'Ms. Akinyi', moderatedSubjects: [] as string[] }
  const other = { id: 'u-9', name: 'Mr. Otieno', moderatedSubjects: [] as string[] }
  const mineSide = { kind: 'side' as const, editId: 'e-m' }
  const mineProposal: Proposal = { ...proposalByHer, byId: 'u-1', by: 'John Doe', choice: mineSide }
  const idsOf = (conflicts: ActiveConflict[]) => conflicts.map((conflict) => conflict.id)
  const withId = (id: string, over: Partial<ActiveConflict> = {}): ActiveConflict => ({ ...crossTeacher, id, ...over })

  it('puts what a person can act on under needs you: settle their own edits, propose, or respond to a proposal', () => {
    const groups = groupConflicts([
      withId('own', { mine: ownEdits.mine, theirs: ownEdits.theirs }),
      withId('open'),
      withId('responds', { proposals: [proposalByHer] }),
    ], me)

    expect(idsOf(groups.needsYou)).toEqual(['own', 'open', 'responds'])
    expect(groups.waiting).toEqual([])
    expect(groups.others).toEqual([])
  })

  it('puts their own conflicts that only another person can move under waiting', () => {
    const groups = groupConflicts([
      withId('mine-waiting', { proposals: [mineProposal] }),
      withId('referred', { referral: { reason: 'party', byId: 'u-2', by: 'Ms. Akinyi', at: 'x' } }),
    ], me)

    expect(idsOf(groups.waiting)).toEqual(['mine-waiting', 'referred'])
    expect(groups.needsYou).toEqual([])
  })

  it('puts a moderator of the subject under needs you, referred or not, and anyone else under others', () => {
    const referred = withId('referred', { referral: { reason: 'party', byId: 'u-1', by: 'John Doe', at: 'x' } })
    const maths = { id: 'u-4', name: 'Ms. Wanjiru', moderatedSubjects: ['subject-maths'] }

    expect(idsOf(groupConflicts([withId('c'), referred], hod).needsYou)).toEqual(['c', 'referred'])
    expect(idsOf(groupConflicts([withId('c'), referred], other).others)).toEqual(['c', 'referred'])
    expect(idsOf(groupConflicts([withId('c')], maths).others)).toEqual(['c'])
  })

  it('does not count someone else\'s own two-device edits for a moderator, who can only watch them', () => {
    expect(idsOf(groupConflicts([ownEdits], hod).others)).toEqual(['c'])
  })

  it('counts a moderator who is also a party as a party', () => {
    const partyAndHod = { id: 'u-1', name: 'John Doe', moderatedSubjects: [SUBJECT] }

    expect(idsOf(groupConflicts([withId('c')], partyAndHod).needsYou)).toEqual(['c'])
    expect(idsOf(groupConflicts([withId('c', { proposals: [mineProposal] })], partyAndHod).waiting)).toEqual(['c'])
  })

  it('keeps the order it was given within each group, and handles none', () => {
    expect(groupConflicts([], me)).toEqual({ needsYou: [], waiting: [], others: [] })
    const mixed = [withId('a'), withId('b', { proposals: [mineProposal] }), withId('c')]
    expect(idsOf(groupConflicts(mixed, me).needsYou)).toEqual(['a', 'c'])
    expect(idsOf(groupConflicts(mixed, her).needsYou)).toEqual(['a', 'b', 'c'])
  })
})

describe('describeConflictCounts', () => {
  const none = { needsYou: [], waiting: [], others: [] }
  const some = (n: number) => Array.from({ length: n }, (_, i) => ({ ...crossTeacher, id: `c${i}` }))

  it('says what is for the person to do', () => {
    expect(describeConflictCounts({ ...none, needsYou: some(1) })).toBe('1 conflict to resolve')
    expect(describeConflictCounts({ ...none, needsYou: some(3) })).toBe('3 conflicts to resolve')
  })

  it('says when the rest is waiting on other people, and when there is nothing to do', () => {
    expect(describeConflictCounts({ ...none, needsYou: some(2), waiting: some(1) })).toBe('2 conflicts to resolve, 1 waiting on others')
    expect(describeConflictCounts({ ...none, waiting: some(2) })).toBe('Nothing for you to do; 2 waiting on others')
    expect(describeConflictCounts(none)).toBe('All conflicts resolved')
  })

  it('does not count what a person can only watch', () => {
    expect(describeConflictCounts({ ...none, others: some(5) })).toBe('All conflicts resolved')
  })
})


describe('describeConflictHeading', () => {
  const none = { needsYou: [], waiting: [], others: [] }
  const some = (n: number) => Array.from({ length: n }, (_, i) => ({ ...crossTeacher, id: `c${i}` }))

  it('is the plain count when the saved data was read', () => {
    expect(describeConflictHeading({ ...none, needsYou: some(2) }, false)).toBe('2 conflicts to resolve')
    expect(describeConflictHeading(none, false)).toBe('All conflicts resolved')
  })

  it('never says all clear after a failed read, whatever was cached', () => {
    expect(describeConflictHeading(none, true)).toBe('Conflicts could not be read')
    expect(describeConflictHeading({ ...none, others: some(3) }, true)).toBe('Conflicts could not be read')
  })

  it('says that what it shows was the last read, when it has something to show', () => {
    expect(describeConflictHeading({ ...none, needsYou: some(1) }, true)).toBe('1 conflict to resolve (as last read; could not refresh)')
    expect(describeConflictHeading({ ...none, waiting: some(2) }, true)).toBe('Nothing for you to do; 2 waiting on others (as last read; could not refresh)')
  })
})

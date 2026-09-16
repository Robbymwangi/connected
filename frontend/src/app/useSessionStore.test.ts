import { describe, expect, it } from 'vitest'
import { score } from '../lib/grading'
import { reduce, seed } from './useSessionStore'

const me = { id: 'u-1', name: 'John Doe', canModerate: false }
const hod = { id: 'u-3', name: 'Mr. Kamau', canModerate: true }
const at = '2026-08-28T09:00:00'

const open = seed.conflicts[0] // two teachers, no proposal
const proposed = seed.conflicts[1] // Ms. Akinyi has proposed
const ownEdits = seed.conflicts[2] // same teacher, two devices

const cellOf = (s: typeof seed, c: typeof open) => s.marks[c.assessmentId][c.studentId][c.criterionId]
const theirsOf = (c: typeof open) => ({ kind: 'side' as const, editId: c.theirs.editId })

describe('resolveConflict', () => {
  it('lets a teacher settle their own two-device edits directly', () => {
    const next = reduce(seed, { type: 'resolveConflict', id: ownEdits.id, choice: theirsOf(ownEdits), note: '', user: me, at })
    expect(cellOf(next, ownEdits)).toEqual({ mark: ownEdits.theirs.mark, sync: 'local', author: 'John Doe' })
    expect(next.conflicts.map((k) => k.id)).not.toContain(ownEdits.id)
    expect(next.history[0]).toMatchObject({ id: ownEdits.id, resolution: { kind: 'self', by: 'John Doe' } })
  })
  it('refuses to let a party settle a cross-teacher conflict alone', () => {
    expect(reduce(seed, { type: 'resolveConflict', id: open.id, choice: theirsOf(open), note: 'please', user: me, at })).toBe(seed)
  })
  it('lets a moderator settle it, but only with a note', () => {
    expect(reduce(seed, { type: 'resolveConflict', id: open.id, choice: theirsOf(open), note: '', user: hod, at })).toBe(seed)
    const next = reduce(seed, { type: 'resolveConflict', id: open.id, choice: theirsOf(open), note: 'moderated', user: hod, at })
    expect(next.history[0].resolution).toEqual({ kind: 'moderated', byId: 'u-3', by: 'Mr. Kamau', choice: theirsOf(open), note: 'moderated' })
    expect(cellOf(next, open)).toEqual({ mark: open.theirs.mark, sync: 'local', author: open.theirs.who })
  })
  it('leaves the cell queued rather than synced, per ADR 0001', () => {
    const next = reduce(seed, { type: 'resolveConflict', id: ownEdits.id, choice: theirsOf(ownEdits), note: '', user: me, at })
    expect(cellOf(next, ownEdits).sync).toBe('local')
  })
})

describe('choice validation at the store boundary', () => {
  it('rejects a side that is not one of the conflict\'s edits', () => {
    expect(reduce(seed, { type: 'resolveConflict', id: ownEdits.id, choice: { kind: 'side', editId: 'nope' }, note: '', user: me, at })).toBe(seed)
    expect(reduce(seed, { type: 'proposeResolution', id: open.id, choice: { kind: 'side', editId: 'nope' }, note: 'n', user: me, at })).toBe(seed)
  })
  it('rejects a corrected mark over the criterion maximum or empty', () => {
    const over = { kind: 'corrected' as const, mark: score(99) }
    expect(reduce(seed, { type: 'resolveConflict', id: ownEdits.id, choice: over, note: '', user: me, at })).toBe(seed)
    expect(reduce(seed, { type: 'proposeResolution', id: open.id, choice: over, note: 'n', user: me, at })).toBe(seed)
  })
  it('a non-party cannot propose or accept', () => {
    const other = { id: 'u-9', name: 'Mr. Otieno', canModerate: false }
    expect(reduce(seed, { type: 'proposeResolution', id: open.id, choice: theirsOf(open), note: 'n', user: other, at })).toBe(seed)
    expect(reduce(seed, { type: 'acceptProposal', id: proposed.id, user: other, at })).toBe(seed)
  })
})

describe('proposeResolution and acceptProposal', () => {
  it('a proposal applies nothing yet and needs a note', () => {
    expect(reduce(seed, { type: 'proposeResolution', id: open.id, choice: theirsOf(open), note: '', user: me, at })).toBe(seed)
    const next = reduce(seed, { type: 'proposeResolution', id: open.id, choice: theirsOf(open), note: 'agreed in moderation', user: me, at })
    expect(cellOf(next, open)).toEqual(cellOf(seed, open))
    expect(next.history).toEqual(seed.history)
    expect(next.conflicts.find((k) => k.id === open.id)?.proposals).toEqual([{ byId: 'u-1', by: 'John Doe', choice: theirsOf(open), note: 'agreed in moderation', at }])
  })
  it('the proposer cannot accept their own proposal', () => {
    const pending = reduce(seed, { type: 'proposeResolution', id: open.id, choice: theirsOf(open), note: 'n', user: me, at })
    expect(reduce(pending, { type: 'acceptProposal', id: open.id, user: me, at })).toBe(pending)
  })
  it('the other party accepting applies it and records both names', () => {
    const next = reduce(seed, { type: 'acceptProposal', id: proposed.id, user: me, at })
    expect(cellOf(next, proposed)).toEqual({ mark: proposed.theirs.mark, sync: 'local', author: proposed.theirs.who })
    expect(next.conflicts.map((k) => k.id)).not.toContain(proposed.id)
    expect(next.history[0].resolution).toMatchObject({ kind: 'agreed', proposedById: 'u-2', proposedBy: 'Ms. Akinyi', acceptedById: 'u-1', acceptedBy: 'John Doe', note: proposed.proposals[0].note })
  })
  it('a counter-proposal is appended, and there is no third', () => {
    const corrected = { kind: 'corrected' as const, mark: score(9) }
    const countered = reduce(seed, { type: 'proposeResolution', id: proposed.id, choice: corrected, note: 'split the difference', user: me, at })
    const k = countered.conflicts.find((x) => x.id === proposed.id)!
    expect(k.proposals).toHaveLength(2)
    expect(k.proposals[1]).toEqual({ byId: 'u-1', by: 'John Doe', choice: corrected, note: 'split the difference', at })
    /* Ms. Akinyi, now the responder, may not propose again. */
    const her = { id: 'u-2', name: 'Ms. Akinyi', canModerate: false }
    expect(reduce(countered, { type: 'proposeResolution', id: proposed.id, choice: theirsOf(proposed), note: 'again', user: her, at })).toBe(countered)
  })
  it('referring after two rounds records the reason as rounds; earlier, as the party', () => {
    const corrected = { kind: 'corrected' as const, mark: score(9) }
    const countered = reduce(seed, { type: 'proposeResolution', id: proposed.id, choice: corrected, note: 'n', user: me, at })
    const her = { id: 'u-2', name: 'Ms. Akinyi', canModerate: false }
    const byRounds = reduce(countered, { type: 'referConflict', id: proposed.id, user: her, at })
    expect(byRounds.conflicts.find((x) => x.id === proposed.id)?.referral).toEqual({ reason: 'rounds', at })
    const byChoice = reduce(seed, { type: 'referConflict', id: open.id, user: me, at })
    expect(byChoice.conflicts.find((x) => x.id === open.id)?.referral).toEqual({ reason: 'party', byId: 'u-1', by: 'John Doe', at })
  })
  it('a referred conflict takes no proposals from parties but a moderator can settle it', () => {
    const referred = reduce(seed, { type: 'referConflict', id: open.id, user: me, at })
    expect(reduce(referred, { type: 'proposeResolution', id: open.id, choice: theirsOf(open), note: 'n', user: me, at })).toBe(referred)
    const settled = reduce(referred, { type: 'resolveConflict', id: open.id, choice: theirsOf(open), note: 'moderated', user: hod, at })
    expect(settled.history[0]).toMatchObject({ id: open.id, referral: { reason: 'party' }, resolution: { kind: 'moderated', by: 'Mr. Kamau' } })
  })
  it('a settled conflict is a no-op afterwards, so a repeated action cannot duplicate history', () => {
    const once = reduce(seed, { type: 'acceptProposal', id: proposed.id, user: me, at })
    expect(reduce(once, { type: 'acceptProposal', id: proposed.id, user: me, at })).toBe(once)
  })
})

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
    const next = reduce(seed, { type: 'updateGrid', assessmentId: scheduled.id, update: (g) => g })
    const grid = next.marks[scheduled.id]
    expect(Object.keys(grid)).toHaveLength(28)
    expect(Object.values(grid)[0].sc1.mark.kind).toBe('empty')
  })
})

import { describe, expect, it } from 'vitest'
import cases from '../../../api/tests/Fixtures/conflict-policy.json'
import type { ActiveConflict, Proposal } from '../fixtures/conflicts'
import { abilityOf, canRefer, type Resolver } from './conflicts'
import { score } from './grading'

/* The server and the client apply one policy table (docs/spec/sync-protocol.md, "Conflict commands"; ADR 0002).
   Both read the same case file, api/tests/Fixtures/conflict-policy.json, so neither can drift without a test failing.
   The client has no forbidden/invalid distinction: it only shows or hides a control, so it compares allowed with
   not allowed. A conflict that is already resolved is not an active conflict, so those cases need no client check. */

const SUBJECT = 'Maths'

type Case = (typeof cases.cases)[number]

const userOf = (label: string): Resolver => ({
  id: `u-${label}`,
  name: `User ${label}`,
  // m and p moderate the subject; nobody else does.
  moderatedSubjects: label === 'm' || label === 'p' ? [SUBJECT] : [],
})

function conflictOf(c: Case): ActiveConflict {
  const side = (label: string, n: number) => ({
    editId: `e-${n}`,
    userId: `u-${label}`,
    who: `User ${label}`,
    mark: score(n * 4),
    at: '2026-10-07T08:00:00',
  })
  const proposals: Proposal[] = c.proposals.map((label, i) => ({
    byId: `u-${label}`,
    by: `User ${label}`,
    choice: { kind: 'side', editId: `e-${i === 0 ? 1 : 2}` },
    note: 'because',
    at: '2026-10-07T08:30:00',
  }))
  return {
    id: 'c',
    assessmentId: 'a1',
    baseVersion: 1,
    studentId: 's1',
    criterionId: 'c1',
    student: 'S',
    criterion: 'C',
    assessment: 'A',
    mine: side(c.sides[0], 1),
    theirs: side(c.sides[1], 2),
    proposals,
    ...(c.referred ? { referral: { reason: 'party' as const, byId: 'u-a', by: 'User a', at: '2026-10-07T08:40:00' } } : {}),
  }
}

function clientAllows(c: Case): boolean {
  const conflict = conflictOf(c)
  const user = userOf(c.actor)
  const ability = abilityOf(conflict, user, SUBJECT)
  switch (c.act) {
    case 'propose':
      return ability.kind === 'propose' || (ability.kind === 'respond' && ability.canCounter)
    case 'accept':
      return ability.kind === 'respond'
    case 'refer':
      return canRefer(conflict, user)
    default:
      return ability.kind === 'resolve'
  }
}

describe('the shared policy cases', () => {
  const active = cases.cases.filter((c) => !c.resolved)

  it('has cases to run', () => {
    expect(active.length).toBeGreaterThan(30)
  })

  it.each(active.map((c) => [c.name, c] as const))('%s', (_name, c) => {
    expect(clientAllows(c)).toBe(c.expected === 'allowed')
  })
})

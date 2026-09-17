import { describe, expect, it } from 'vitest'
import type { Assessment } from '../fixtures/assessments'
import type { Grid } from '../fixtures/marks'
import type { Student } from '../fixtures/students'
import {
  assessmentsInScope,
  criterionBreakdown,
  needingAttention,
  outcomesFor,
  quantile,
  summarise,
  trend,
  YEAR_TO_DATE,
  type Data,
} from './analytics'
import { ABSENT, EMPTY, score } from './grading'

const base = { stream: '4W', year: 2025, entered: 0, total: 3, sync: 'synced' as const, version: 1 }
const eng1: Assessment = { ...base, id: 'e1', subject: 'English', name: 'CAT 1', term: 'Term 1', date: '2025-03-10', status: 'finalized' }
const eng2: Assessment = { ...base, id: 'e2', subject: 'English', name: 'CAT 2', term: 'Term 2', date: '2025-08-18', status: 'in-progress' }
const maths: Assessment = { ...base, id: 'm1', subject: 'Maths', name: 'CAT 1', term: 'Term 1', date: '2025-03-11', status: 'complete' }
const planned: Assessment = { ...base, id: 'p1', subject: 'Science', name: 'Practical', term: 'Term 2', date: '2025-09-08', status: 'scheduled' }
const other: Assessment = { ...eng1, id: 'o1', stream: '5A' }

const roster: Student[] = ['s1', 's2', 's3'].map((id) => ({ id, classId: 'class-4w', name: id, gender: 'F', dob: '2016-01-01' }))
const cell = (m: ReturnType<typeof score> | typeof ABSENT | typeof EMPTY) => ({ mark: m, sync: 'synced' as const })
/* English rubric: c1/20 c2/15 c3/15 c4/10 = 60. s1 scores 48 (80%), s2 absent on c2, s3 incomplete. */
const grid: Grid = {
  s1: { c1: cell(score(16)), c2: cell(score(12)), c3: cell(score(12)), c4: cell(score(8)) },
  s2: { c1: cell(score(10)), c2: cell(ABSENT), c3: cell(score(9)), c4: cell(score(5)) },
  s3: { c1: cell(score(10)), c2: cell(EMPTY), c3: cell(score(9)), c4: cell(score(5)) },
}
const data: Data = {
  assessments: [eng1, eng2, maths, planned, other],
  marks: { e2: grid },
  records: [
    { studentId: 's1', assessmentId: 'e1', total: 30 }, // 50%
    { studentId: 's2', assessmentId: 'e1', total: 24 }, // 40%
    { studentId: 's3', assessmentId: 'e2', total: 18 }, // 30%, the fallback for the incomplete row
  ],
  roster,
}

describe('assessmentsInScope', () => {
  const all = { term: YEAR_TO_DATE, assessment: '' }
  it('selects by stream and subject, drops scheduled, and orders by date', () => {
    expect(assessmentsInScope({ stream: '4W', subject: 'English' }, all, data.assessments).map((a) => a.id)).toEqual(['e1', 'e2'])
    expect(assessmentsInScope({ stream: '4W', subject: 'Overall' }, all, data.assessments).map((a) => a.id)).toEqual(['e1', 'm1', 'e2'])
  })
  it('narrows by term and assessment name', () => {
    expect(assessmentsInScope({ stream: '4W', subject: 'Overall' }, { term: 'Term 1', assessment: '' }, data.assessments).map((a) => a.id)).toEqual(['e1', 'm1'])
    expect(assessmentsInScope({ stream: '4W', subject: 'Overall' }, { term: YEAR_TO_DATE, assessment: 'CAT 2' }, data.assessments).map((a) => a.id)).toEqual(['e2'])
  })
})

describe('outcomesFor', () => {
  it('scores a complete row, marks an absence, falls back to the record, else missing', () => {
    const o = outcomesFor(eng2, data)
    expect(o[0]).toMatchObject({ studentId: 's1', status: 'scored', total: 48, max: 60, pct: 80 })
    expect(o[1]).toEqual({ studentId: 's2', status: 'absent' })
    expect(o[2]).toMatchObject({ studentId: 's3', status: 'scored', total: 18, pct: 30 })
    expect(outcomesFor(eng1, data)[2]).toEqual({ studentId: 's3', status: 'missing' })
  })
})

describe('summarise', () => {
  it('excludes absences from every denominator but completeness', () => {
    const s = summarise([outcomesFor(eng2, data)])
    expect(s.scored).toBe(2)
    expect(s.absent).toBe(1)
    expect(s.passRate).toBe(50)
    expect(s.meanPct).toBe(55)
    expect(s.completeness).toBe(100)
    expect(s.levels).toEqual({ EE: 1, ME: 0, AE: 0, BE: 1 })
    expect(s.histogram.find((b) => b.bin === '80–89')?.count).toBe(1)
    expect(s.histogram.find((b) => b.bin === '30–39')?.count).toBe(1)
  })
  it('counts missing rows against completeness', () => {
    const s = summarise([outcomesFor(eng1, data)])
    expect(s.missing).toBe(1)
    expect(s.completeness).toBeCloseTo(66.67, 1)
  })
  it('is honest about no data', () => {
    const s = summarise([])
    expect(s.passRate).toBeNull()
    expect(s.meanPct).toBeNull()
    expect(s.spread).toBeNull()
    expect(s.completeness).toBeNull()
  })
  it('puts 100 percent in the last bin', () => {
    const s = summarise([[{ studentId: 'x', status: 'scored', total: 60, max: 60, pct: 100 }]])
    expect(s.histogram[9].count).toBe(1)
  })
})

describe('quantile', () => {
  it('interpolates', () => {
    expect(quantile([10, 20, 30, 40], 0.5)).toBe(25)
    expect(quantile([10, 20, 30, 40], 0.25)).toBe(17.5)
    expect(quantile([], 0.5)).toBeNull()
  })
})

describe('criterionBreakdown', () => {
  it('averages the share achieved per criterion from grids, skipping absent and empty cells', () => {
    const rows = criterionBreakdown([eng1, eng2], data)
    const c2 = rows.find((r) => r.name === 'Written Expr.')
    expect(c2).toEqual({ name: 'Written Expr.', pct: 80, n: 1 })
    const c1 = rows.find((r) => r.name === 'Comprehension')
    expect(c1?.n).toBe(3)
    expect(c1?.pct).toBeCloseTo(60, 5)
  })
})

describe('trend and needingAttention', () => {
  it('reports each assessment in order, keyed uniquely across subjects', () => {
    const t = trend([eng1, eng2], data)
    expect(t.map((p) => p.label)).toEqual(['CAT 1, Term 1', 'CAT 2, Term 2'])
    const overall = trend([eng1, maths], data)
    expect(new Set(overall.map((p) => p.key)).size).toBe(2)
    expect(overall.map((p) => p.label)).toEqual(['CAT 1, Term 1', 'CAT 1, Term 1'])
    expect(t[0].passRate).toBe(50)
    expect(t[1].meanPct).toBe(55)
  })
  it('lists students averaging below the pass mark, lowest first', () => {
    const a = needingAttention([eng1, eng2], data)
    expect(a.map((x) => x.studentId)).toEqual(['s3', 's2'])
    expect(a[0]).toMatchObject({ meanPct: 30, latestPct: 30, level: 'BE', scored: 1 })
  })
})

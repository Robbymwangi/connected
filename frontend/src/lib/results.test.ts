import { describe, expect, it } from 'vitest'
import type { Assessment } from '../fixtures/assessments'
import type { Grid } from '../fixtures/marks'
import { ABSENT, score } from './grading'
import { resultsForStudent, trendBySubject } from './results'

const base = { stream: '4W', year: 2025, entered: 0, total: 28, status: 'finalized' as const, sync: 'synced' as const, version: 1 }
const cat1: Assessment = { ...base, id: 'x1', subject: 'English', name: 'CAT 1', term: 'Term 1', date: '2025-03-10' }
const cat2: Assessment = { ...base, id: 'x2', subject: 'English', name: 'CAT 2', term: 'Term 2', date: '2025-08-18' }
const other: Assessment = { ...base, id: 'x3', subject: 'Maths', name: 'CAT 1', term: 'Term 1', date: '2025-03-11', stream: '5A' }

const full: Grid = { s1: { c1: { mark: score(16), sync: 'synced' }, c2: { mark: score(12), sync: 'synced' }, c3: { mark: score(14), sync: 'synced' }, c4: { mark: score(8), sync: 'synced' } } }
const partial: Grid = { s1: { c1: { mark: score(16), sync: 'synced' }, c2: { mark: ABSENT, sync: 'synced' }, c3: { mark: score(14), sync: 'synced' }, c4: { mark: score(8), sync: 'synced' } } }

describe('resultsForStudent', () => {
  it('takes a complete grid row over the record, and derives the level', () => {
    const r = resultsForStudent('s1', '4W', [cat2], { x2: full }, [{ studentId: 's1', assessmentId: 'x2', total: 30 }])
    expect(r).toHaveLength(1)
    expect(r[0]).toMatchObject({ total: 50, max: 60, level: 'EE', source: 'grid' })
  })
  it('falls back to the record when the grid row is incomplete or absent', () => {
    const r = resultsForStudent('s1', '4W', [cat2], { x2: partial }, [{ studentId: 's1', assessmentId: 'x2', total: 30 }])
    expect(r[0]).toMatchObject({ total: 30, level: 'AE', source: 'record' })
  })
  it('skips assessments with neither, and other streams', () => {
    expect(resultsForStudent('s1', '4W', [cat1, other], {}, [])).toEqual([])
  })
  it('orders by assessment date', () => {
    const recs = [{ studentId: 's1', assessmentId: 'x2', total: 40 }, { studentId: 's1', assessmentId: 'x1', total: 30 }]
    expect(resultsForStudent('s1', '4W', [cat2, cat1], {}, recs).map((r) => r.assessmentId)).toEqual(['x1', 'x2'])
  })
})

describe('trendBySubject', () => {
  it('groups percentages by subject in order', () => {
    const recs = [{ studentId: 's1', assessmentId: 'x1', total: 30 }, { studentId: 's1', assessmentId: 'x2', total: 45 }]
    const t = trendBySubject(resultsForStudent('s1', '4W', [cat1, cat2], {}, recs))
    expect(t.English.map((p) => p.pct)).toEqual([50, 75])
    expect(t.English[0].label).toBe('CAT 1, Term 1')
  })
})

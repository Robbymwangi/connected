import { describe, expect, it } from 'vitest'
import { HISTOGRAM_BINS, type Summary } from './analytics'
import { answer, SUGGESTIONS, type AssistantReport } from './assistant'

const summary: Summary = {
  assessments: 2, scored: 20, absent: 1, missing: 0, passRate: 75, meanPct: 61.4,
  spread: { min: 30, max: 92, iqr: 20 }, completeness: 100,
  levels: { EE: 4, ME: 11, AE: 3, BE: 2 },
  histogram: HISTOGRAM_BINS.map((bin) => ({ bin, count: 2 })),
}
const report: AssistantReport = {
  scopeLabel: '4W · English',
  summary,
  criteria: [{ name: 'Comprehension', pct: 71 }, { name: 'Written Expr.', pct: 54 }, { name: 'Oral Fluency', pct: 75 }],
  trend: [{ label: 'CAT 1, Term 1', passRate: 62, meanPct: 55 }, { label: 'CAT 2, Term 2', passRate: 75, meanPct: 61.4 }],
  attention: [{ studentId: 's2', meanPct: 38, latestPct: 36 }, { studentId: 's6', meanPct: 44, latestPct: 47 }],
  roster: [{ id: 's2', classId: 'class-4w', name: 'Kofi Mensah', gender: 'M', dob: '2016-07-04' }, { id: 's6', classId: 'class-4w', name: 'Amara Kamau', gender: 'M', dob: '2016-02-15' }],
}

describe('answer', () => {
  it('summarises with the report\'s own figures', () => {
    const a = answer(SUGGESTIONS[0], report)
    expect(a.text).toContain('75% of 20 results')
    expect(a.text).toContain('mean of 61%')
    expect(a.text).toContain('15 are meeting or exceeding')
    expect(a.text).toContain('1 absence excluded')
    expect(a.chart).toBe('trend')
  })
  it('names the students needing support, lowest first', () => {
    const a = answer('who is struggling?', report)
    expect(a.text).toContain('2 students are averaging below 50%')
    expect(a.text.indexOf('Kofi Mensah')).toBeLessThan(a.text.indexOf('Amara Kamau'))
  })
  it('describes the trend from first to last assessment', () => {
    const a = answer(SUGGESTIONS[2], report)
    expect(a.text).toContain('from 62% (CAT 1, Term 1) to 75% (CAT 2, Term 2): up 13 points')
    expect(a.chart).toBe('trend')
  })
  it('ranks criteria', () => {
    const a = answer('strongest and weakest criteria', report)
    expect(a.text).toContain('Strongest criterion in 4W · English is Oral Fluency at 75%')
    expect(a.text).toContain('weakest is Written Expr. at 54%')
    expect(a.chart).toBe('criteria')
  })
  it('routes "strong" and "weak" to criteria only when a criterion is meant', () => {
    expect(answer('what is the weakest area?', report).chart).toBe('criteria')
    expect(answer('strong performance overall?', report).chart).toBe('trend')
  })
  it('says what it cannot do and falls back to the summary', () => {
    const a = answer('write a letter to the parents', report)
    expect(a.text).toMatch(/^I can only answer about the report on screen/)
  })
  it('is honest about empty scopes', () => {
    const empty = { ...report, summary: { ...summary, scored: 0 }, trend: [], attention: [], criteria: [] }
    expect(answer(SUGGESTIONS[0], empty).text).toContain('nothing to summarise')
    expect(answer(SUGGESTIONS[1], empty).text).toContain('cannot say who needs support')
    expect(answer(SUGGESTIONS[2], empty).text).toContain('at least two')
    expect(answer(SUGGESTIONS[3], empty).text).toContain('none in scope')
  })
})

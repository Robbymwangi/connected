import { describe, expect, it } from 'vitest'
import type { ReportSummaryResponse } from '../../api/reports'
import { toReportView } from './reportModel'

const response: ReportSummaryResponse = {
  filters: { stream: '4W', subject_id: 'subject-1', year: 2026, term: null, assessment_name: null, assessment_id: null },
  available_years: [2026],
  available_assessments: ['CAT 1'],
  student_outcomes: [],
  summary: {
    pass_rate: 75,
    mean_score: 68.5,
    score_spread: { min: 30, max: 90, iqr: 20 },
    entry_completeness: 80,
    performance_levels: { EE: 1, ME: 2, AE: 1, BE: 0 },
    histogram: [{ bin: '0-9', count: 0 }],
    trend: [{ key: 'Maths|CAT 1|1', subject: 'Maths', label: 'CAT 1, 1', date: '2026-02-01', passRate: 75, meanScore: 68.5 }],
    criterion_breakdown: [{ name: 'Accuracy', pct: 70, n: 4 }],
    attention_list: [{ studentId: 'student-1', meanScore: 40, latestScore: 35, level: 'BE', scored: 2 }],
    decline_list: [],
    net_level_movement: 0,
  },
}

describe('toReportView', () => {
  it('maps server fields into the chart and student view without recomputing analytics', () => {
    const roster = [{ id: 'student-1', classId: 'class-4w', name: 'A. Student', gender: 'F' as const, dob: '2015-01-01' }]
    const report = toReportView(response, { stream: '4W', subject: 'Maths' }, roster)

    expect(report.assessmentCount).toBe(1)
    expect(report.summary.scored).toBe(4)
    expect(report.summary.meanPct).toBe(68.5)
    expect(report.trend[0].meanPct).toBe(68.5)
    expect(report.attention[0]).toMatchObject({ studentId: 'student-1', meanPct: 40, latestPct: 35 })
    expect(report.roster).toBe(roster)
  })
})
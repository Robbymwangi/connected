import type { ReportSummaryResponse } from '../../api/reports'
import type { Scope } from '../../lib/analytics'
import type { Student } from '../../fixtures/students'

export type ReportView = {
  scope: Scope
  assessmentCount: number
  summary: {
    scored: number
    passRate: number | null
    meanPct: number | null
    spread: { min: number; max: number; iqr: number } | null
    completeness: number | null
    levels: ReportSummaryResponse['summary']['performance_levels']
    histogram: ReportSummaryResponse['summary']['histogram']
  }
  criteria: ReportSummaryResponse['summary']['criterion_breakdown']
  trend: Array<ReportSummaryResponse['summary']['trend'][number] & { meanPct: number | null }>
  attention: Array<{
    studentId: string
    meanPct: number
    latestPct: number
    level: 'EE' | 'ME' | 'AE' | 'BE'
    scored: number
  }>
  roster: Student[]
}

export function toReportView(response: ReportSummaryResponse, scope: Scope, roster: Student[]): ReportView {
  const summary = response.summary

  return {
    scope,
    assessmentCount: summary.trend.length,
    summary: {
      scored: Object.values(summary.performance_levels).reduce((total, count) => total + count, 0),
      passRate: summary.pass_rate,
      meanPct: summary.mean_score,
      spread: summary.score_spread,
      completeness: summary.entry_completeness,
      levels: summary.performance_levels,
      histogram: summary.histogram,
    },
    criteria: summary.criterion_breakdown,
    trend: summary.trend.map((point) => ({ ...point, meanPct: point.meanScore })),
    attention: summary.attention_list.map((student) => ({
      studentId: student.studentId,
      meanPct: student.meanScore,
      latestPct: student.latestScore,
      level: student.level,
      scored: student.scored,
    })),
    roster,
  }
}
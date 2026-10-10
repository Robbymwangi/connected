import { apiFetch, type FetchImpl } from './client'

export type ReportSummaryFilters = {
  stream: string
  year: number
  subjectId?: string | null
  term?: number | null
  assessmentName?: string | null
}

export type ReportSummaryResponse = {
  filters: {
    stream: string
    subject_id: string | null
    year: number
    term: number | null
    assessment_name: string | null
  }
  available_years: number[]
  available_assessments: string[]
  summary: {
    pass_rate: number | null
    mean_score: number | null
    score_spread: { min: number; max: number; iqr: number } | null
    entry_completeness: number | null
    performance_levels: Record<'EE' | 'ME' | 'AE' | 'BE', number>
    histogram: Array<{ bin: string; count: number }>
    trend: Array<{
      key: string
      subject: string
      label: string
      date: string
      passRate: number | null
      meanScore: number | null
    }>
    criterion_breakdown: Array<{ name: string; pct: number; n: number }>
    attention_list: Array<{
      studentId: string
      meanScore: number
      latestScore: number
      level: 'EE' | 'ME' | 'AE' | 'BE'
      scored: number
    }>
    decline_list: Array<{ studentId: string; latestScore: number; priorMean: number }>
    net_level_movement: number
  }
}

export function getReportSummary(
  filters: ReportSummaryFilters,
  token: string,
  fetchImpl: FetchImpl = fetch,
): Promise<ReportSummaryResponse> {
  const params = new URLSearchParams({ stream: filters.stream, year: String(filters.year) })
  if (filters.subjectId) params.set('subject_id', filters.subjectId)
  if (filters.term != null) params.set('term', String(filters.term))
  if (filters.assessmentName) params.set('assessment_name', filters.assessmentName)

  return apiFetch(`/api/reports/summary?${params.toString()}`, { token }, fetchImpl)
}
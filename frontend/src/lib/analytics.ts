import type { PerformanceLevel } from './grading'

/* The vocabulary Reports shares with the server's figures. Every KPI is computed
   once, server-side, as a SQL aggregate (docs/spec/analytics.md, build plan 4.1);
   nothing here calculates a figure. Absent students are excluded from every
   denominator except entry completeness by the server's explicit predicate. */

/* Share of the maximum at which a student passes. A constant until grading schemes
   arrive with the API. */
export const PASS_MARK_PCT = 50

export type Scope = {
  stream: string
  subject: string | 'Overall'
}

export type ReportFilters = {
  /* 'Year to Date' or a term name. */
  term: string
  /* An assessment name such as 'CAT 2', or '' for all. */
  assessment: string
}

export const YEAR_TO_DATE = 'Year to Date'

export type Summary = {
  assessments: number
  scored: number
  absent: number
  missing: number
  passRate: number | null
  meanPct: number | null
  spread: { min: number; max: number; iqr: number } | null
  /* Entered (scored or absent) over expected (roster size per assessment). */
  completeness: number | null
  levels: Record<PerformanceLevel, number>
  histogram: Array<{ bin: string; count: number }>
}

export const HISTOGRAM_BINS = ['0–9', '10–19', '20–29', '30–39', '40–49', '50–59', '60–69', '70–79', '80–89', '90–100']

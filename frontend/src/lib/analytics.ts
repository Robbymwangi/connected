import type { Assessment } from '../fixtures/assessments'
import type { Grid } from '../fixtures/marks'
import type { ResultRecord } from '../fixtures/results'
import { rubricFor, type Subject } from '../fixtures/rubrics'
import type { Student } from '../fixtures/students'
import { performanceLevel, rowTotal, type PerformanceLevel } from './grading'

/* Report figures, computed from the marks the app holds rather than typed in.
   Everything is a percentage of the rubric maximum so assessments with different
   maxima combine. Absent students are excluded from every denominator except
   entry completeness, where an absence is a recorded fact. */

/* Share of the maximum at which a student passes. A constant until grading schemes
   arrive with the API. */
export const PASS_MARK_PCT = 50

export type Scope = {
  stream: string
  subject: Subject | 'Overall'
}

export type ReportFilters = {
  /* 'Year to Date' or a term name. */
  term: string
  /* An assessment name such as 'CAT 2', or '' for all. */
  assessment: string
}

export const YEAR_TO_DATE = 'Year to Date'

export type Outcome =
  | { studentId: string; status: 'scored'; total: number; max: number; pct: number }
  | { studentId: string; status: 'absent' }
  | { studentId: string; status: 'missing' }

export type Data = {
  assessments: Assessment[]
  marks: Record<string, Grid | undefined>
  records: ResultRecord[]
  roster: Student[]
}

/* The assessments a scope and filters select: that stream, that subject (or any),
   that term (or any), that assessment name (or any), and not merely scheduled. */
export function assessmentsInScope(scope: Scope, filters: ReportFilters, assessments: Assessment[]): Assessment[] {
  return assessments
    .filter((a) => a.stream === scope.stream)
    .filter((a) => scope.subject === 'Overall' || a.subject === scope.subject)
    .filter((a) => filters.term === YEAR_TO_DATE || a.term === filters.term)
    .filter((a) => filters.assessment === '' || a.name === filters.assessment)
    .filter((a) => a.status !== 'scheduled')
    .sort((x, y) => x.date.localeCompare(y.date))
}

/* One outcome per student on the roster for one assessment. A complete grid row
   is a score; a row with any absent cell is an absence; an incomplete row falls
   back to the record on file, and failing that is missing. */
export function outcomesFor(a: Assessment, data: Data): Outcome[] {
  const rubric = rubricFor(a.subject)
  const max = rubric.reduce((s, c) => s + c.max, 0)
  const grid = data.marks[a.id]
  return data.roster.map((student) => {
    const row = grid?.[student.id]
    if (row) {
      const marks = rubric.map((c) => row[c.id]?.mark ?? { kind: 'empty' as const })
      if (marks.some((m) => m.kind === 'absent')) return { studentId: student.id, status: 'absent' }
      const total = rowTotal(marks)
      if (total !== null) return { studentId: student.id, status: 'scored', total, max, pct: (total / max) * 100 }
    }
    const record = data.records.find((r) => r.studentId === student.id && r.assessmentId === a.id)
    if (record) return { studentId: student.id, status: 'scored', total: record.total, max, pct: (record.total / max) * 100 }
    return { studentId: student.id, status: 'missing' }
  })
}

type Scored = Extract<Outcome, { status: 'scored' }>

export function scored(outcomes: Outcome[]): Scored[] {
  return outcomes.filter((o): o is Scored => o.status === 'scored')
}

export function mean(values: number[]): number | null {
  return values.length === 0 ? null : values.reduce((s, v) => s + v, 0) / values.length
}

/* Linear-interpolated quantile of sorted values, q in [0, 1]. */
export function quantile(sorted: number[], q: number): number | null {
  if (sorted.length === 0) return null
  const pos = (sorted.length - 1) * q
  const lo = Math.floor(pos)
  const hi = Math.ceil(pos)
  return sorted[lo] + (sorted[hi] - sorted[lo]) * (pos - lo)
}

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

export function summarise(outcomeSets: Outcome[][]): Summary {
  const all = outcomeSets.flat()
  const s = scored(all)
  const pcts = s.map((o) => o.pct).sort((a, b) => a - b)
  const absent = all.filter((o) => o.status === 'absent').length
  const missing = all.filter((o) => o.status === 'missing').length
  const levels: Record<PerformanceLevel, number> = { EE: 0, ME: 0, AE: 0, BE: 0 }
  for (const o of s) levels[performanceLevel(o.total, o.max)]++
  const histogram = HISTOGRAM_BINS.map((bin) => ({ bin, count: 0 }))
  for (const p of pcts) histogram[Math.min(9, Math.floor(p / 10))].count++
  const q1 = quantile(pcts, 0.25)
  const q3 = quantile(pcts, 0.75)
  return {
    assessments: outcomeSets.length,
    scored: s.length,
    absent,
    missing,
    passRate: s.length === 0 ? null : (s.filter((o) => o.pct >= PASS_MARK_PCT).length / s.length) * 100,
    meanPct: mean(pcts),
    spread: pcts.length === 0 || q1 === null || q3 === null ? null : { min: pcts[0], max: pcts[pcts.length - 1], iqr: q3 - q1 },
    completeness: all.length === 0 ? null : ((s.length + absent) / all.length) * 100,
    levels,
    histogram,
  }
}

/* Average share achieved per criterion, from grids only (records hold totals).
   Keyed by criterion name so the same criterion across assessments combines. */
export function criterionBreakdown(assessments: Assessment[], data: Data): Array<{ name: string; pct: number; n: number }> {
  const acc = new Map<string, { sum: number; n: number }>()
  for (const a of assessments) {
    const grid = data.marks[a.id]
    if (!grid) continue
    for (const c of rubricFor(a.subject)) {
      for (const student of data.roster) {
        const mark = grid[student.id]?.[c.id]?.mark
        if (!mark || mark.kind !== 'score') continue
        const e = acc.get(c.name) ?? { sum: 0, n: 0 }
        e.sum += (mark.value / c.max) * 100
        e.n += 1
        acc.set(c.name, e)
      }
    }
  }
  return [...acc.entries()].map(([name, { sum, n }]) => ({ name, pct: sum / n, n }))
}

export type TrendPoint = {
  /* Stable identity for aligning two scopes: subject, name, and term together,
     because an Overall scope holds a CAT 1 for every subject. */
  key: string
  subject: Subject
  label: string
  passRate: number | null
  meanPct: number | null
}

/* Per assessment, in date order: pass rate and mean, for the trend line. */
export function trend(assessments: Assessment[], data: Data): TrendPoint[] {
  return assessments.map((a) => {
    const s = summarise([outcomesFor(a, data)])
    return { key: `${a.subject}|${a.name}|${a.term}`, subject: a.subject, label: `${a.name}, ${a.term}`, passRate: s.passRate, meanPct: s.meanPct }
  })
}

/* Students whose average across the scoped assessments is below the pass mark,
   lowest first, with their latest result. */
export function needingAttention(assessments: Assessment[], data: Data): Array<{ studentId: string; meanPct: number; latestPct: number; level: PerformanceLevel; scored: number }> {
  const byStudent = new Map<string, Scored[]>()
  for (const a of assessments) {
    for (const o of scored(outcomesFor(a, data))) {
      byStudent.set(o.studentId, [...(byStudent.get(o.studentId) ?? []), o])
    }
  }
  const out: Array<{ studentId: string; meanPct: number; latestPct: number; level: PerformanceLevel; scored: number }> = []
  for (const [studentId, outcomes] of byStudent) {
    const m = mean(outcomes.map((o) => o.pct))
    if (m === null || m >= PASS_MARK_PCT) continue
    const latest = outcomes[outcomes.length - 1]
    out.push({ studentId, meanPct: m, latestPct: latest.pct, level: performanceLevel(latest.total, latest.max), scored: outcomes.length })
  }
  return out.sort((x, y) => x.meanPct - y.meanPct)
}

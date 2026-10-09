import type { Assessment } from '../fixtures/assessments'
import type { Grid } from '../fixtures/marks'
import type { ResultRecord } from '../fixtures/results'
import { rubricFor, type Criterion } from '../fixtures/rubrics'
import { performanceLevel, rowTotal, type PerformanceLevel } from './grading'

/* A student's results across assessments, from two sources: the live marking grid
   where one exists and holds a complete row, otherwise the results on record.
   The grid wins because it is more recent than anything synced. */

export type StudentResult = {
  assessmentId: string
  subject: string
  name: string
  term: string
  date: string
  total: number
  max: number
  level: PerformanceLevel
  source: 'grid' | 'record'
}

export function resultsForStudent(
  studentId: string,
  stream: string,
  assessments: Assessment[],
  grids: Record<string, Grid | undefined>,
  records: ResultRecord[],
  criteriaBySubject?: Readonly<Record<string, Criterion[]>>,
): StudentResult[] {
  const out: StudentResult[] = []
  for (const a of assessments) {
    if (a.stream !== stream) continue
    const rubric = criteriaBySubject?.[a.subject] ?? rubricFor(a.subject)
    const rubricMax = rubric.reduce((s, c) => s + c.max, 0)
    const record = records.find((r) => r.studentId === studentId && r.assessmentId === a.id)
    const row = grids[a.id]?.[studentId]
    const fromGrid = row ? rowTotal(rubric.map((c) => row[c.id]?.mark ?? { kind: 'empty' })) : null
    const max = fromGrid !== null ? rubricMax : record?.max ?? rubricMax
    if (max <= 0) continue

    const total = fromGrid ?? record?.total ?? null
    if (total === null) continue

    out.push({
      assessmentId: a.id,
      subject: a.subject,
      name: a.name,
      term: a.term,
      date: a.date,
      total,
      max,
      level: fromGrid !== null ? performanceLevel(total, max) : record?.level ?? performanceLevel(total, max),
      source: fromGrid !== null ? 'grid' : 'record',
    })
  }
  return out.sort((x, y) => x.date.localeCompare(y.date))
}

/* Percentages per subject in date order, for a trend line. */
export function trendBySubject(results: StudentResult[]): Record<string, Array<{ label: string; pct: number }>> {
  const out: Record<string, Array<{ label: string; pct: number }>> = {}
  for (const r of results) {
    ;(out[r.subject] ??= []).push({ label: `${r.name}, ${r.term}`, pct: Math.round((r.total / r.max) * 100) })
  }
  return out
}

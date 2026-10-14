import type { Assessment, LifecycleStatus } from '../fixtures/assessments'

/* Pure filtering and ordering for the assessments queue. */

export const STATUS_GROUPS = {
  All: ['scheduled', 'in-progress', 'complete', 'finalized'],
  Open: ['scheduled', 'in-progress'],
  'Needs action': ['complete'],
  Closed: ['finalized'],
} as const satisfies Record<string, readonly LifecycleStatus[]>

export type StatusGroup = keyof typeof STATUS_GROUPS

export const ALL_TERMS = 'All terms'
export const ALL_SUBJECTS = 'All subjects'

export type QueueFilters = {
  year: number
  status: StatusGroup
  term: string
  subject: string
}

/* The queue opens on the calendar year, not the newest year holding data: the
   teacher's current work is this year's, and a fixed year goes stale the day the
   calendar turns. */
export function defaultFilters(now: Date = new Date()): QueueFilters {
  return {
    year: now.getFullYear(),
    status: 'All',
    term: ALL_TERMS,
    subject: ALL_SUBJECTS,
  }
}

/* Years the filter offers, newest first: the calendar year and the two before it,
   plus any year a record already carries, so no record is unreachable by filter. */
export function queueYears(list: Assessment[], now: Date = new Date()): string[] {
  const current = now.getFullYear()
  const years = new Set([current, current - 1, current - 2, ...list.map((a) => a.year)])
  return [...years].sort((a, b) => b - a).map(String)
}

export function filterAssessments(list: Assessment[], filters: QueueFilters): Assessment[] {
  const statuses: readonly LifecycleStatus[] = STATUS_GROUPS[filters.status]
  return list.filter(
    (a) =>
      a.year === filters.year &&
      statuses.includes(a.status) &&
      (filters.term === ALL_TERMS || a.term === filters.term) &&
      (filters.subject === ALL_SUBJECTS || a.subject === filters.subject),
  )
}

/* What needs the teacher next comes first: finalizing, then entry in progress,
   then upcoming, then the closed ones. Stable within a group. */
const QUEUE_PRIORITY: Record<LifecycleStatus, number> = {
  complete: 0,
  'in-progress': 1,
  scheduled: 2,
  finalized: 3,
}

export function sortQueue(list: Assessment[]): Assessment[] {
  return [...list].sort((a, b) => QUEUE_PRIORITY[a.status] - QUEUE_PRIORITY[b.status])
}

export type TermGroup = { label: string; assessments: Assessment[] }

/* A stream's assessments as a mark book reads them: by term, newest term first, and in date
   order within a term. The term is held as text ("Term 2"), so its number orders it. */
export function groupByTerm(list: Assessment[]): TermGroup[] {
  const termNumber = (term: string) => Number(/\d+/.exec(term)?.[0] ?? 0)
  const groups = new Map<string, { year: number; term: string; assessments: Assessment[] }>()
  for (const assessment of list) {
    const key = `${assessment.year}|${assessment.term}`
    const group = groups.get(key) ?? { year: assessment.year, term: assessment.term, assessments: [] }
    group.assessments.push(assessment)
    groups.set(key, group)
  }
  return [...groups.values()]
    .sort((a, b) => b.year - a.year || termNumber(b.term) - termNumber(a.term))
    .map((group) => ({
      label: `${group.term}, ${group.year}`,
      assessments: [...group.assessments].sort((a, b) => a.date.localeCompare(b.date) || a.name.localeCompare(b.name)),
    }))
}

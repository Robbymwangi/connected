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

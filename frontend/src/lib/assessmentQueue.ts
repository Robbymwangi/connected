import type { Assessment, LifecycleStatus } from '../fixtures/assessments'

/* Pure filtering and ordering for the assessments queue. */

export const STATUS_GROUPS = {
  All: ['scheduled', 'in-progress', 'complete', 'finalized', 'reports-generated'],
  Open: ['scheduled', 'in-progress'],
  'Needs action': ['complete'],
  Closed: ['finalized', 'reports-generated'],
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

export const DEFAULT_FILTERS: QueueFilters = {
  year: 2025,
  status: 'All',
  term: ALL_TERMS,
  subject: ALL_SUBJECTS,
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
  'reports-generated': 4,
}

export function sortQueue(list: Assessment[]): Assessment[] {
  return [...list].sort((a, b) => QUEUE_PRIORITY[a.status] - QUEUE_PRIORITY[b.status])
}

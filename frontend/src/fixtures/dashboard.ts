import type { NavId } from '../layout/navigation'

/* Static stand-ins for what the dashboard will read from the local store. */

export const lastSession = {
  label: 'Grade 4W: English CAT 2, Term 2',
  detail: 'Mark Capture Grid, 23 of 28 students entered',
  time: 'Resumed 14 min ago',
}

export const progress = {
  studentsEnteredThisWeek: 142,
  needsReview: 1,
  enteredToday: 20,
}

export const classOptions = ['Grade 4, English', 'Grade 4, Math', 'Grade 5, All', 'Grade 5, Science']

/* Current assessment against the previous one for the selected class. */
export const classStats = {
  assessment: 'CAT 2, 2nd Term',
  comparedTo: 'CAT 1',
  mean: { current: 58.5, previous: 54.2 },
  cohort: { current: 40.4, previous: 43.1 },
}

export type RecentItem = {
  primary: string
  secondary: string
  to: NavId
}

export const recentlyAccessed: RecentItem[] = [
  { primary: 'Grade 4W, English', secondary: 'Last opened: 12 min ago', to: 'classes' },
  { primary: 'Grade 5, All', secondary: 'Last opened: 1h ago', to: 'classes' },
  { primary: 'Grade 4W: Amina', secondary: 'Student profile: yesterday', to: 'classes' },
]

export type PendingAssessment = {
  primary: string
  secondary: string
}

export const pendingAssessments: PendingAssessment[] = []

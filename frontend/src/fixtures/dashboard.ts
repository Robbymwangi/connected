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

export type ClassStats = {
  assessment: string
  comparedTo: string
  mean: { current: number; previous: number }
  cohort: { current: number; previous: number }
}

/* Current assessment against the previous one, per class. The card's selector picks
   the entry; every class has its own figures. */
export const classStats: Record<string, ClassStats> = {
  'Grade 4, English': {
    assessment: 'CAT 2, 2nd Term',
    comparedTo: 'CAT 1',
    mean: { current: 58.5, previous: 54.2 },
    cohort: { current: 40.4, previous: 43.1 },
  },
  'Grade 4, Maths': {
    assessment: 'CAT 2, 2nd Term',
    comparedTo: 'CAT 1',
    mean: { current: 64.1, previous: 66.8 },
    cohort: { current: 52.3, previous: 49.7 },
  },
  'Grade 5, All': {
    assessment: 'End of Term, 1st Term',
    comparedTo: 'CAT 2',
    mean: { current: 61.7, previous: 58.9 },
    cohort: { current: 47.2, previous: 44.0 },
  },
  'Grade 5, Science': {
    assessment: 'Practical 1, 2nd Term',
    comparedTo: 'Practical 0',
    mean: { current: 55.4, previous: 55.1 },
    cohort: { current: 38.9, previous: 41.6 },
  },
}

export const classOptions = Object.keys(classStats)

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

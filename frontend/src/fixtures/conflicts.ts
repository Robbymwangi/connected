import { score, type Mark } from '../lib/grading'

/* Active sync conflicts: the same mark edited on two devices from the same base
   version. Shared by the dashboard banner, the marking grid, and the Sync screen.

   Keyed by ids so the grid can find its cell. The timestamps are shown to the user
   as information; they play no part in detection or resolution, which is a stale
   base version and a person's choice respectively. */
export type ActiveConflict = {
  id: string
  assessmentId: string
  studentId: string
  criterionId: string
  student: string
  criterion: string
  assessment: string
  /* Both sides are full marks: either teacher may have recorded an absence. */
  myMark: Mark
  myDate: string
  otherTeacher: string
  otherMark: Mark
  otherDate: string
}

export const activeConflicts: ActiveConflict[] = [
  {
    id: 'c-1',
    assessmentId: 'a1',
    studentId: 's1',
    criterionId: 'c3',
    student: 'Wanjiku Njoroge',
    criterion: 'Oral Fluency',
    assessment: 'English CAT 2, Term 2',
    myMark: score(14),
    myDate: 'Aug 27, 2026, 14:32',
    otherTeacher: 'Ms. Akinyi',
    otherMark: score(11),
    otherDate: 'Aug 27, 2026, 16:05',
  },
  {
    id: 'c-2',
    assessmentId: 'a1',
    studentId: 's2',
    criterionId: 'c2',
    student: 'Kofi Mensah',
    criterion: 'Written Expr.',
    assessment: 'English CAT 2, Term 2',
    myMark: score(8),
    myDate: 'Aug 27, 2026, 14:45',
    otherTeacher: 'Ms. Akinyi',
    otherMark: score(10),
    otherDate: 'Aug 27, 2026, 16:12',
  },
]

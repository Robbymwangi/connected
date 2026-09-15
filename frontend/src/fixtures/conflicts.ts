/* Active sync conflicts: the same mark edited on two devices from the same base
   version. Shared by the dashboard banner and the Sync screen. */
export type ActiveConflict = {
  id: string
  student: string
  criterion: string
  assessment: string
  myValue: number
  myDate: string
  otherTeacher: string
  otherValue: number
  otherDate: string
}

export const activeConflicts: ActiveConflict[] = [
  {
    id: 'c-1',
    student: 'Wanjiku',
    criterion: 'Practical skills',
    assessment: 'English CAT 2, Term 2',
    myValue: 14,
    myDate: 'Aug 27, 2026, 14:32',
    otherTeacher: 'Ms. Akinyi',
    otherValue: 11,
    otherDate: 'Aug 27, 2026, 16:05',
  },
  {
    id: 'c-2',
    student: 'Kofi Mensah',
    criterion: 'Written expression',
    assessment: 'English CAT 2, Term 2',
    myValue: 8,
    myDate: 'Aug 27, 2026, 14:45',
    otherTeacher: 'Ms. Akinyi',
    otherValue: 10,
    otherDate: 'Aug 27, 2026, 16:12',
  },
]

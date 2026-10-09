import { liveQuery } from 'dexie'
import { useEffect, useState } from 'react'
import { localDatabaseFor } from '../lib/localDatabase'
import { mapSchoolDirectory, type SchoolDirectory } from '../lib/schoolDirectory'

export type SchoolDirectoryState =
  | { status: 'loading' }
  | { status: 'ready'; data: SchoolDirectory }
  | { status: 'error' }

export function useSchoolDirectory(userId: string): SchoolDirectoryState {
  const database = localDatabaseFor(userId)
  const [state, setState] = useState<SchoolDirectoryState>({ status: 'loading' })

  useEffect(() => {
    const subscription = liveQuery(() =>
      database.transaction(
        'r',
        [
          database.assessments,
          database.classes,
          database.class_subjects,
          database.criteria,
          database.enrolments,
          database.students,
          database.subjects,
          database.teacher_assignments,
          database.users,
        ],
        async () => {
          const [assessments, classes, classSubjects, criteria, enrolments, students, subjects, teacherAssignments, users] =
            await Promise.all([
              database.assessments.toArray(),
              database.classes.toArray(),
              database.class_subjects.toArray(),
              database.criteria.toArray(),
              database.enrolments.toArray(),
              database.students.toArray(),
              database.subjects.toArray(),
              database.teacher_assignments.toArray(),
              database.users.toArray(),
            ])

          return mapSchoolDirectory({
            assessments,
            classes,
            classSubjects,
            criteria,
            enrolments,
            students,
            subjects,
            teacherAssignments,
            users,
          })
        },
      ),
    ).subscribe({
      next: (data) => setState({ status: 'ready', data }),
      error: () => setState({ status: 'error' }),
    })

    return () => subscription.unsubscribe()
  }, [database])

  return state
}
import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it } from 'vitest'
import type { Assessment } from '../fixtures/assessments'
import { LocalDatabase } from './localDatabase'
import { mapSchoolDirectory } from './schoolDirectory'
import { readRecentAccesses, recentAccessForLocation, RECENT_ACCESS_KEY, RECENT_ACCESS_LIMIT, rememberRecentAccess, type RecentAccess } from './recentAccess'

const databases: LocalDatabase[] = []

afterEach(async () => {
  for (const database of databases.splice(0)) {
    database.close()
    await database.delete()
  }
})

function visit(index: number): RecentAccess {
  return {
    key: `class:class-${index}`,
    primary: `Grade ${index} · Stream ${index}A`,
    secondary: '20 students · 4 subjects',
    destination: { screen: 'classes', classId: `class-${index}` },
  }
}

const directory = mapSchoolDirectory({
  assessments: [],
  classes: [{ id: 'class-1', version: 1, grade: 'Grade 4', stream: '4W', classTeacherId: 'teacher-1' }],
  classSubjects: [{ id: 'link-1', version: 1, classId: 'class-1', subjectId: 'subject-1' }],
  criteria: [{ id: 'criterion-1', version: 1, subjectId: 'subject-1', name: 'Reading', maxScore: 20 }],
  enrolments: [{ id: 'enrolment-1', version: 1, studentId: 'student-1', classId: 'class-1', year: 2026 }],
  students: [{ id: 'student-1', version: 1, name: 'Amina', gender: 'F', dob: '2016-01-19' }],
  subjects: [{ id: 'subject-1', version: 1, name: 'English' }],
  teacherAssignments: [],
  users: [{ id: 'teacher-1', version: 1, name: 'Teacher One', email: 'teacher@example.test' }],
})

const assessment: Assessment = {
  id: 'assessment-1', subject: 'English', stream: '4W', name: 'CAT 1', term: 'Term 1', year: 2026,
  date: '2026-03-01', version: 1, entered: 0, total: 1, status: 'scheduled', sync: 'synced',
}

describe('recent access storage', () => {
  it('builds recent destinations from assessment and student locations', () => {
    expect(recentAccessForLocation({ screen: 'assessments', assessmentId: assessment.id, view: 'grid' }, directory, [assessment])).toMatchObject({
      key: 'assessment:assessment-1:grid',
      primary: 'English: CAT 1',
      secondary: 'Stream 4W · Term 1',
      destination: { screen: 'assessments', assessmentId: 'assessment-1', view: 'grid' },
    })
    expect(recentAccessForLocation({ screen: 'classes', classId: 'class-1', studentId: 'student-1' }, directory, [assessment])).toMatchObject({
      key: 'student:class-1:student-1',
      primary: 'Amina',
      secondary: 'Student profile · Grade 4 · Stream 4W',
      destination: { screen: 'classes', classId: 'class-1', studentId: 'student-1' },
    })
  })

  it('keeps the newest five destinations first and moves a revisit to the top', async () => {
    const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
    databases.push(database)
    for (let index = 1; index <= 6; index++) await rememberRecentAccess(database, visit(index))
    await rememberRecentAccess(database, visit(4))

    expect((await readRecentAccesses(database)).map((item) => item.key)).toEqual([
      'class:class-4', 'class:class-6', 'class:class-5', 'class:class-3', 'class:class-2',
    ])
    expect(await database.metadata.get(RECENT_ACCESS_KEY)).toBeDefined()
    expect(RECENT_ACCESS_LIMIT).toBe(5)
  })

  it('returns an empty list when no recent destinations are stored', async () => {
    const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
    databases.push(database)

    await expect(readRecentAccesses(database)).resolves.toEqual([])
  })
})
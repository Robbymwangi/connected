import { describe, expect, it } from 'vitest'
import type { LocalRecord } from './localDatabase'
import { mapSchoolDirectory, type SchoolDirectorySource } from './schoolDirectory'

const row = (id: string, fields: Record<string, unknown> = {}): LocalRecord => ({ id, version: 1, ...fields })

describe('mapSchoolDirectory', () => {
  it('joins classes, subjects, criteria, teachers, and year-specific student enrolments', () => {
    const source: SchoolDirectorySource = {
      classes: [row('class-1', { grade: 'Grade 4', stream: '4W', classTeacherId: 'user-1' })],
      classSubjects: [row('link-1', { classId: 'class-1', subjectId: 'subject-1' })],
      criteria: [row('criterion-1', { subjectId: 'subject-1', name: 'Comprehension', maxScore: 20 })],
      enrolments: [
        row('enrolment-1', { studentId: 'student-1', classId: 'class-1', year: 2025 }),
        row('enrolment-old', { studentId: 'student-1', classId: 'class-1', year: 2024 }),
      ],
      students: [row('student-1', { name: 'Amina Osei', gender: 'F', dob: '2016-01-19' })],
      subjects: [row('subject-1', { name: 'English' })],
      teacherAssignments: [row('assignment-1', { userId: 'user-1', classId: 'class-1', subjectId: 'subject-1' })],
      users: [row('user-1', { name: 'Jane Teacher', email: 'jane@example.test' })],
      assessments: [row('assessment-1', { classId: 'class-1', year: 2025 })],
    }

    const directory = mapSchoolDirectory(source)

    expect(directory.years).toEqual([2025, 2024])
    expect(directory.subjects).toEqual(['English'])
    expect(directory.criteriaBySubject.English).toEqual([
      { id: 'criterion-1', name: 'Comprehension', max: 20 },
    ])
    expect(directory.classesForYear(2025)).toEqual([
      {
        id: 'class-1',
        grade: 'Grade 4',
        stream: '4W',
        teacher: 'Jane Teacher',
        enrolment: 1,
        subjects: ['English'],
        assessments: 1,
      },
    ])
    expect(directory.classesForYear(2024)[0]?.enrolment).toBe(1)
    expect(directory.studentsForYear(2025)).toEqual([
      { id: 'student-1', classId: 'class-1', name: 'Amina Osei', gender: 'F', dob: '2016-01-19' },
    ])
    expect(directory.teachers).toMatchObject([
      {
        id: 'user-1',
        name: 'Jane Teacher',
        initials: 'JT',
        homeStream: '4W',
        subjectsByStream: { '4W': ['English'] },
      },
    ])
  })

  it('ignores soft-deleted and orphaned reference rows', () => {
    const source: SchoolDirectorySource = {
      classes: [row('class-1', { grade: 'Grade 4', stream: '4W', deletedAt: '2026-01-01' })],
      classSubjects: [row('link-1', { classId: 'missing', subjectId: 'subject-1' })],
      criteria: [],
      enrolments: [row('enrolment-1', { studentId: 'student-1', classId: 'missing', year: 2025 })],
      students: [row('student-1', { name: 'Amina Osei', gender: 'F', dob: '2016-01-19' })],
      subjects: [row('subject-1', { name: 'English' })],
      teacherAssignments: [],
      users: [],
      assessments: [],
    }

    const directory = mapSchoolDirectory(source)

    expect(directory.years).toEqual([2025])
    expect(directory.classesForYear(2025)).toEqual([])
    expect(directory.studentsForYear(2025)).toEqual([])
    expect(directory.teachers).toEqual([])
  })
})
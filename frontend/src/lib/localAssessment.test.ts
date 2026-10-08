import { describe, expect, it } from 'vitest'
import { mapSchoolDirectory } from './schoolDirectory'
import { createLocalAssessmentRecord } from './localAssessment'

const directory = mapSchoolDirectory({
  assessments: [],
  classes: [{ id: 'class-1', version: 1, grade: 'Grade 4', stream: '4W' }],
  classSubjects: [{ id: 'link-1', version: 1, classId: 'class-1', subjectId: 'subject-1' }],
  criteria: [{ id: 'criterion-1', version: 1, subjectId: 'subject-1', name: 'Reading', maxScore: 20 }],
  enrolments: [{ id: 'enrolment-1', version: 1, studentId: 'student-1', classId: 'class-1', year: 2025 }],
  students: [{ id: 'student-1', version: 1, name: 'Amina', gender: 'F', dob: '2016-01-19' }],
  subjects: [{ id: 'subject-1', version: 1, name: 'English' }],
  teacherAssignments: [],
  users: [],
})

describe('createLocalAssessmentRecord', () => {
  it('creates a version-0 scheduled wire row using local class and subject ids', () => {
    const record = createLocalAssessmentRecord({
      subject: 'English', stream: '4W', name: 'CAT 1', term: 'Term 1', year: 2025,
      date: '2025-05-12', entered: 0, total: 1, status: 'scheduled', sync: 'pending',
    }, 'assessment-1', directory)

    expect(record).toEqual({
      id: 'assessment-1', version: 0, classId: 'class-1', subjectId: 'subject-1',
      name: 'CAT 1', term: 'Term 1', year: 2025, date: '2025-05-12', status: 'scheduled', sync: 'pending',
      pendingBaseVersion: 0,
      pendingFields: { classId: 'class-1', subjectId: 'subject-1', name: 'CAT 1', term: 'Term 1', year: 2025, date: '2025-05-12' },
    })
  })

  it('refuses a class or subject that is no longer offered locally', () => {
    expect(() => createLocalAssessmentRecord({
      subject: 'Maths', stream: '4W', name: 'CAT 1', term: 'Term 1', year: 2025,
      date: '2025-05-12', entered: 0, total: 1, status: 'scheduled', sync: 'pending',
    }, 'assessment-1', directory)).toThrow('Assessment class or subject is not available in the local directory')
  })
})
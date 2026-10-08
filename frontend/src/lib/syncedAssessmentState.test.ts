import { describe, expect, it } from 'vitest'
import { mapSchoolDirectory } from './schoolDirectory'
import { mapSyncedAssessmentState } from './syncedAssessmentState'

const directory = mapSchoolDirectory({
  assessments: [],
  classes: [{ id: 'class-1', version: 1, grade: '7', stream: '7B', classTeacherId: 'teacher-1' }],
  classSubjects: [{ id: 'link-1', version: 1, classId: 'class-1', subjectId: 'subject-1' }],
  criteria: [{ id: 'criterion-1', version: 1, subjectId: 'subject-1', name: 'Reading', maxScore: 20 }],
  enrolments: [{ id: 'enrolment-1', version: 1, studentId: 'student-1', classId: 'class-1', year: 2026 }],
  students: [{ id: 'student-1', version: 1, name: 'Amina Njeri', gender: 'F', dob: '2014-02-01' }],
  subjects: [{ id: 'subject-1', version: 1, name: 'Kiswahili' }],
  teacherAssignments: [{ id: 'assignment-1', version: 1, userId: 'teacher-1', classId: 'class-1', subjectId: 'subject-1' }],
  users: [{ id: 'teacher-1', version: 1, name: 'Teacher One', email: 'teacher@example.test' }],
})

describe('mapSyncedAssessmentState', () => {
  it('builds an assessment and grid from wire rows using the year roster and rubric', () => {
    const state = mapSyncedAssessmentState({
      userId: 'teacher-1',
      directory,
      assessments: [{
        id: 'assessment-1', version: 2, classId: 'class-1', subjectId: 'subject-1', name: 'CAT 1',
        term: 'Term 1', year: 2026, date: '2026-05-12', status: 'scheduled',
      }],
      marks: [
        { id: 'mark-1', version: 1, assessmentId: 'assessment-1', studentId: 'student-1', criterionId: 'criterion-1', markKind: 'absent', score: null, lastEditedBy: 'teacher-1' },
      ],
      conflicts: [],
      results: [{ id: 'result-1', version: 1, assessmentId: 'assessment-1', studentId: 'student-1', total: 14, max: 18, level: 'ME' }],
    })

    expect(state.assessments).toEqual([{
      id: 'assessment-1', subject: 'Kiswahili', stream: '7B', name: 'CAT 1', term: 'Term 1', year: 2026,
      date: '2026-05-12', version: 2, entered: 1, total: 1, status: 'complete', sync: 'synced',
    }])
    expect(state.marks['assessment-1']['student-1']['criterion-1']).toEqual({
      mark: { kind: 'absent' }, sync: 'synced', author: 'Teacher One',
    })
    expect(state.resultRecords).toEqual([{ studentId: 'student-1', assessmentId: 'assessment-1', total: 14, max: 18, level: 'ME' }])
  })

  it('orients an active conflict for a party, but keeps author names suitable for moderators', () => {
    const state = mapSyncedAssessmentState({
      userId: 'teacher-2',
      directory,
      assessments: [{
        id: 'assessment-1', version: 2, classId: 'class-1', subjectId: 'subject-1', name: 'CAT 1',
        term: 'Term 1', year: 2026, date: '2026-05-12', status: 'scheduled',
      }],
      marks: [{
        id: 'mark-1', version: 2, assessmentId: 'assessment-1', studentId: 'student-1',
        criterionId: 'criterion-1', markKind: 'score', score: 12, lastEditedBy: 'teacher-1',
      }],
      conflicts: [{
        id: 'conflict-1', version: 1, markId: 'mark-1', baseVersion: 1,
        sideA: { editId: 'edit-1', userId: 'teacher-1', who: 'Teacher One', markKind: 'score', score: 12, at: null },
        sideB: { editId: 'edit-2', userId: 'teacher-2', who: 'Teacher Two', markKind: 'absent', score: null, at: null },
        proposals: [], referral: null, resolution: null, resolvedAt: null,
      }],
      results: [],
    })

    expect(state.conflicts).toHaveLength(1)
    expect(state.conflicts[0].mine).toMatchObject({ userId: 'teacher-2', who: 'Teacher Two', mark: { kind: 'absent' } })
    expect(state.conflicts[0].theirs).toMatchObject({ userId: 'teacher-1', who: 'Teacher One', mark: { kind: 'score', value: 12 } })
  })

  it('keeps an unacknowledged local finalize pending on a later snapshot', () => {
    const state = mapSyncedAssessmentState({
      userId: 'teacher-1',
      directory,
      assessments: [{
        id: 'assessment-1', version: 2, classId: 'class-1', subjectId: 'subject-1', name: 'CAT 1',
        term: 'Term 1', year: 2026, date: '2026-05-12', status: 'finalized', sync: 'pending',
      }],
      marks: [],
      conflicts: [],
      results: [],
    })

    expect(state.assessments[0]).toMatchObject({ status: 'finalized', sync: 'pending', version: 2 })
  })
})
import { describe, expect, it } from 'vitest'
import type { OutboxEntry } from './outbox'
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
      mark: { kind: 'absent' }, sync: 'synced', baseVersion: 1, author: 'Teacher One',
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

/* A conflict between two teachers on one mark, and what this device has queued about it. */
describe('a conflict with commands of this device on top', () => {
  const assessment = {
    id: 'assessment-1', version: 2, classId: 'class-1', subjectId: 'subject-1', name: 'CAT 1',
    term: 'Term 1', year: 2026, date: '2026-05-12', status: 'scheduled',
  }
  const mark = {
    id: 'mark-1', version: 2, assessmentId: 'assessment-1', studentId: 'student-1',
    criterionId: 'criterion-1', markKind: 'score', score: 12, lastEditedBy: 'teacher-1',
  }
  const conflict = (over: Record<string, unknown> = {}) => ({
    id: 'conflict-1', version: 3, markId: 'mark-1', baseVersion: 1,
    sideA: { editId: 'edit-1', userId: 'teacher-1', who: 'Teacher One', markKind: 'score', score: 12, at: null },
    sideB: { editId: 'edit-2', userId: 'teacher-2', who: 'Teacher Two', markKind: 'score', score: 15, at: null },
    proposals: [], referral: null, resolution: null, resolvedAt: null,
    ...over,
  })
  const command = (fields: Record<string, unknown>, over: Partial<OutboxEntry> = {}): OutboxEntry => ({
    id: crypto.randomUUID(), seq: 1, table: 'conflicts', recordId: 'conflict-1', kind: 'command', baseVersion: 3,
    fields, at: '2026-10-09T08:00:00.000Z', state: 'queued', ...over,
  })
  const snapshot = (conflicts: unknown[], commands: OutboxEntry[] = [], userId = 'teacher-2') => mapSyncedAssessmentState({
    userId, userName: 'Teacher Two', directory, assessments: [assessment], marks: [mark],
    conflicts: conflicts as never, results: [], commands,
  })
  const side = { kind: 'side', editId: 'edit-1' }

  it('carries the subject id of its assessment, and the version of the conflict itself, not the mark\'s', () => {
    const [active] = snapshot([conflict()]).conflicts

    expect(active).toMatchObject({ subjectId: 'subject-1', conflictVersion: 3, baseVersion: 1 })
    expect(active.local).toBeUndefined()
  })

  it('counts a command the server has answered but the pull has not delivered in the version it is shown at', () => {
    const [active] = snapshot([conflict()], [command({ referral: { byId: 'teacher-2' } }, { state: 'acked', ackVersion: 4 })]).conflicts

    expect(active.conflictVersion).toBe(4)
    expect(active.local).toBe(true)
    expect(active.referral).toMatchObject({ reason: 'party', byId: 'teacher-2', by: 'Teacher Two' })
  })

  it('shows a queued proposal on the active conflict, marked as waiting to sync', () => {
    const [active] = snapshot([conflict()], [command({ proposal: { byId: 'teacher-2', choice: side, note: 'check the script' } })]).conflicts

    expect(active.local).toBe(true)
    expect(active.proposals).toEqual([{ byId: 'teacher-2', by: 'Teacher Two', choice: side, note: 'check the script', at: '2026-10-09T08:00:00.000Z' }])
  })

  it('moves a conflict this device has resolved into history and shows the chosen mark in the grid as local', () => {
    const own = conflict({ sideB: { editId: 'edit-2', userId: 'teacher-2', who: 'Teacher Two', markKind: 'score', score: 15, at: null } })
    const resolve = command({ resolution: { kind: 'moderated', byId: 'teacher-2', choice: side, note: 'blind re-mark' } })

    const state = snapshot([own], [resolve])

    expect(state.conflicts).toHaveLength(0)
    expect(state.history).toHaveLength(1)
    expect(state.history[0]).toMatchObject({ id: 'conflict-1', local: true, resolution: { kind: 'moderated', by: 'Teacher Two', note: 'blind re-mark' } })
    expect(state.marks['assessment-1']['student-1']['criterion-1']).toMatchObject({
      mark: { kind: 'score', value: 12 }, sync: 'local', author: 'Teacher One', baseVersion: 2,
    })
  })

  it('shows a corrected mark authored by the person who resolved it', () => {
    const resolve = command({ resolution: { kind: 'moderated', byId: 'teacher-2', choice: { kind: 'corrected', mark: { kind: 'score', value: 9 } }, note: 'n' } })

    const state = snapshot([conflict()], [resolve])

    expect(state.marks['assessment-1']['student-1']['criterion-1']).toMatchObject({
      mark: { kind: 'score', value: 9 }, sync: 'local', author: 'Teacher Two',
    })
  })

  it('lets the server win once its row is resolved, with no local marker and the cell as the server has it', () => {
    const resolved = conflict({
      version: 4, resolution: { kind: 'moderated', byId: 'teacher-3', by: 'Mr. Kamau', choice: side, note: 'done' }, resolvedAt: '2026-10-09T07:00:00Z',
    })

    const state = snapshot([resolved], [command({ resolution: { kind: 'moderated', byId: 'teacher-2', choice: side, note: 'late' } })])

    expect(state.conflicts).toHaveLength(0)
    expect(state.history[0].local).toBeUndefined()
    expect(state.history[0].resolution).toMatchObject({ by: 'Mr. Kamau' })
    expect(state.marks['assessment-1']['student-1']['criterion-1'].sync).toBe('synced')
  })

  it('does not call an assessment conflicted because of a conflict that is already resolved', () => {
    const resolved = conflict({ resolution: { kind: 'auto' }, resolvedAt: '2026-10-09T07:00:00Z' })

    expect(snapshot([resolved]).assessments[0].sync).toBe('synced')
    expect(snapshot([conflict()]).assessments[0].sync).toBe('conflict')
  })

  it('does not call an assessment conflicted once this device has resolved the only conflict on it', () => {
    const resolve = command({ resolution: { kind: 'moderated', byId: 'teacher-2', choice: side, note: 'n' } })

    expect(snapshot([conflict()], [resolve]).assessments[0].sync).not.toBe('conflict')
  })

  it('ignores a failed command', () => {
    const [active] = snapshot([conflict()], [command({ referral: { byId: 'teacher-2' } }, { state: 'failed', reason: 'no' })]).conflicts

    expect(active.local).toBeUndefined()
    expect(active.referral).toBeUndefined()
  })
})


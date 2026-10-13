import { describe, expect, it } from 'vitest'
import type { LocalRecord } from './localDatabase'
import type { OutboxEntry } from './outbox'
import { actionsFor, closable, commentView, mayCloseTerm, mayUnlock, mayWriteRemarks, termViews } from './termComments'
import { DIRECTOR_OF_STUDIES } from './session'

const AT = '2026-10-12T08:00:00.000Z'
const comment = (over: Partial<LocalRecord> = {}): LocalRecord => ({
  id: 'k1', version: 2, studentId: 's1', year: 2026, term: 1, termClosureId: 't1', authorId: null, body: 'Generated.', state: 'draft', ...over,
})
const command = (fields: Record<string, unknown>, over: Partial<OutboxEntry> = {}): OutboxEntry => ({
  seq: 1, id: 'e1', table: 'comments', recordId: 'k1', kind: 'command', baseVersion: 2, fields, at: AT, state: 'queued', ...over,
})
const sitting = (over: Partial<LocalRecord> = {}): LocalRecord => ({ id: 'a1', version: 1, classId: 'c1', year: 2026, term: 1, status: 'finalized', ...over })

describe('termViews', () => {
  it('reports each term open or closed, its sittings, its comments as shown, and its cards', () => {
    const terms = termViews({
      classId: 'c1',
      year: 2026,
      assessments: [sitting(), sitting({ id: 'a2', status: 'scheduled' }), sitting({ id: 'a3', term: 2 }), sitting({ id: 'a4', classId: 'other' })],
      closures: [{ id: 't1', version: 1, classId: 'c1', year: 2026, term: 1 }, { id: 'gone', version: 2, classId: 'c1', year: 2026, term: 3, deletedAt: AT }],
      comments: [comment(), comment({ id: 'k2', studentId: 's2', state: 'accepted' }), comment({ id: 'k3', deletedAt: AT })],
      reports: [{ id: 'r1', version: 1, studentId: 's2', year: 2026, term: 1 }],
      outbox: [command({ accept: { byId: 'u1' } })],
    })

    expect(terms.map((view) => [view.term, view.closureId, view.assessments, view.finalized, view.comments.length, view.reportsIssued])).toEqual([
      [1, 't1', 2, 1, 2, 1], [2, null, 1, 1, 0, 0], [3, null, 0, 0, 0, 0],
    ])
    expect(terms[0].comments[0]).toMatchObject({ id: 'k1', state: 'accepted', local: true })
    expect(terms.map(closable)).toEqual([false, true, false])
  })
})

describe('commentView', () => {
  it('flags a stand-in draft and a refused last command', () => {
    expect(commentView(comment({ body: '[Stand-in] Steady.' }), [])).toMatchObject({ standIn: true, refused: null })
    expect(commentView(comment(), [command({ accept: { byId: 'u1' } }, { state: 'failed', reason: 'an accepted comment is final until an unlock' })]))
      .toMatchObject({ state: 'draft', refused: 'an accepted comment is final until an unlock' })
  })

  it('offers the actions the state takes', () => {
    const view = (state: string) => actionsFor(commentView(comment({ state }), []) as never)
    expect(['pending', 'draft', 'failed', 'accepted'].map(view)).toEqual([
      ['edit', 'regenerate'], ['edit', 'accept', 'regenerate'], ['edit', 'regenerate'], [],
    ])
  })
})

describe('mayCloseTerm and mayUnlock', () => {
  it('lets only a Director of Studies close a term, however the teacher is assigned', () => {
    expect(mayCloseTerm({ role: DIRECTOR_OF_STUDIES })).toBe(true)
    expect(mayCloseTerm({ role: 'Teacher' })).toBe(false)
  })

  it('lets a Director of Studies unlock any subject, and an HOD only the subjects they moderate', () => {
    expect(mayUnlock({ role: DIRECTOR_OF_STUDIES, moderatedSubjectIds: [] }, 'maths')).toBe(true)
    expect(mayUnlock({ role: 'Teacher', moderatedSubjectIds: ['maths'] }, 'maths')).toBe(true)
    expect(mayUnlock({ role: 'Teacher', moderatedSubjectIds: ['maths'] }, 'science')).toBe(false)
    expect(mayUnlock({ role: 'Teacher', moderatedSubjectIds: [] }, undefined)).toBe(false)
  })
})

describe('mayWriteRemarks', () => {
  it('lets the stream\'s class teacher or a Director of Studies act on its remarks, and nobody else', () => {
    expect(mayWriteRemarks({ id: 'u1', role: 'Teacher' }, 'u1')).toBe(true)
    expect(mayWriteRemarks({ id: 'u9', role: DIRECTOR_OF_STUDIES }, null)).toBe(true)
    expect(mayWriteRemarks({ id: 'u2', role: 'Teacher' }, 'u1')).toBe(false)
    expect(mayWriteRemarks({ id: 'u2', role: 'Teacher' }, null)).toBe(false)
  })
})

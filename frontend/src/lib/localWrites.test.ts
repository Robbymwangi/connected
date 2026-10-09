import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { score } from './grading'
import { assertDisplayFlags } from './displayFlags.testing'
import { LocalDatabase, type LocalRecord } from './localDatabase'
import { createAssessments, finalizeAssessmentRecord, writeMarkCells } from './localWrites'
import { entriesForRecord } from './outbox'

const AT = '2026-10-09T08:00:00.000Z'
const LATER = '2026-10-09T08:05:00.000Z'
const CREATE = { classId: 'c1', subjectId: 'sub1', name: 'CAT 1', term: 'Term 1', year: 2025, date: '2025-05-12' }

let database: LocalDatabase

beforeEach(() => {
  database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
})

afterEach(async () => {
  database.close()
  await database.delete()
})

function newAssessment(id: string): { record: LocalRecord; createFields: Record<string, unknown> } {
  return { record: { id, version: 0, ...CREATE, status: 'scheduled', sync: 'pending' }, createFields: CREATE }
}

function cell(mark = score(9), baseVersion?: number) {
  return { mark, sync: 'local' as const, ...(baseVersion === undefined ? {} : { baseVersion }) }
}

function edit(id: string, studentId: string, criterionId: string, mark = score(9), baseVersion?: number) {
  return { id, studentId, criterionId, cell: cell(mark, baseVersion) }
}

async function entries(table: 'assessments' | 'marks', id: string) {
  return entriesForRecord(database, table, id)
}

describe('createAssessments', () => {
  it('writes the record and one create entry at base 0, without status', async () => {
    await createAssessments(database, [newAssessment('a1'), newAssessment('a2')], AT)

    expect(await database.assessments.get('a1')).toMatchObject({ id: 'a1', version: 0, status: 'scheduled', sync: 'pending' })
    expect(await database.assessments.get('a1')).not.toHaveProperty('pendingFields')
    const [entry] = await entries('assessments', 'a1')
    expect(entry).toMatchObject({ kind: 'patch', baseVersion: 0, fields: CREATE, at: AT, state: 'queued' })
    expect(Object.keys(entry.fields)).not.toContain('status')
    expect(await database.outbox.count()).toBe(2)
    await assertDisplayFlags(database)
  })

  it('writes nothing when the entry is refused', async () => {
    const bad = { record: { id: 'a1', version: 0, sync: 'pending' }, createFields: {} }

    await expect(createAssessments(database, [newAssessment('a2'), bad], AT)).rejects.toThrow()

    expect(await database.assessments.count()).toBe(0)
    expect(await database.outbox.count()).toBe(0)
  })
})

describe('finalizeAssessmentRecord', () => {
  it('finalizes a created assessment as its own entry and seeds the shadow of the server values', async () => {
    await database.assessments.put({ id: 'a1', version: 4, ...CREATE, status: 'scheduled' })

    expect(await finalizeAssessmentRecord(database, 'a1', 'u1', AT)).toBe(true)

    expect(await database.assessments.get('a1')).toMatchObject({
      status: 'finalized', finalizedBy: 'u1', finalizedAt: AT, sync: 'pending',
      serverShadow: { status: 'scheduled', finalizedBy: null, finalizedAt: null },
    })
    const [entry] = await entries('assessments', 'a1')
    expect(entry).toMatchObject({
      kind: 'finalize', baseVersion: 4, fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
    })
    await assertDisplayFlags(database)
  })

  it('waits behind an unsent create with a null base and no shadow', async () => {
    await createAssessments(database, [newAssessment('a1')], AT)

    await finalizeAssessmentRecord(database, 'a1', 'u1', LATER)

    const list = await entries('assessments', 'a1')
    expect(list.map((entry) => [entry.kind, entry.baseVersion])).toEqual([['patch', 0], ['finalize', null]])
    expect(list[0].fields).toEqual(CREATE)
    expect(await database.assessments.get('a1')).not.toHaveProperty('serverShadow')
    await assertDisplayFlags(database)
  })

  it('does nothing for an assessment that is finalized, deleted, or unknown', async () => {
    await database.assessments.bulkPut([
      { id: 'done', version: 2, status: 'finalized' },
      { id: 'gone', version: 2, status: 'scheduled', deletedAt: AT },
    ])

    expect(await finalizeAssessmentRecord(database, 'done', 'u1', AT)).toBe(false)
    expect(await finalizeAssessmentRecord(database, 'gone', 'u1', AT)).toBe(false)
    expect(await finalizeAssessmentRecord(database, 'missing', 'u1', AT)).toBe(false)
    expect(await database.outbox.count()).toBe(0)
  })

  it('does not finalize twice', async () => {
    await database.assessments.put({ id: 'a1', version: 4, ...CREATE, status: 'scheduled' })

    await finalizeAssessmentRecord(database, 'a1', 'u1', AT)
    expect(await finalizeAssessmentRecord(database, 'a1', 'u1', LATER)).toBe(false)

    expect(await entries('assessments', 'a1')).toHaveLength(1)
  })
})

describe('writeMarkCells', () => {
  beforeEach(async () => {
    await database.assessments.put({ id: 'a1', version: 2, ...CREATE, status: 'scheduled' })
  })

  it('creates a first mark at base 0 with its identity fields', async () => {
    const saved = await writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1')], 'Teacher', AT)

    expect(await database.marks.get('m1')).toMatchObject({
      id: 'm1', version: 0, assessmentId: 'a1', studentId: 's1', criterionId: 'k1',
      markKind: 'score', score: 9, sync: 'pending', localAuthor: 'Teacher',
    })
    const [entry] = await entries('marks', 'm1')
    expect(entry).toMatchObject({
      kind: 'patch', baseVersion: 0,
      fields: { assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 9 },
    })
    expect(saved).toEqual([{
      studentId: 's1', criterionId: 'k1', cell: { mark: score(9), sync: 'local', baseVersion: 0, author: 'Teacher' },
    }])
    await assertDisplayFlags(database)
  })

  it('edits a synced mark at the version the teacher saw, with changed fields only, and seeds the shadow', async () => {
    await database.marks.put({ id: 'm1', version: 6, assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 12 })

    await writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1', score(14), 6)], 'Teacher', AT)

    expect(await database.marks.get('m1')).toMatchObject({
      version: 6, score: 14, sync: 'pending', serverShadow: { markKind: 'score', score: 12 },
    })
    const [entry] = await entries('marks', 'm1')
    expect(entry).toMatchObject({ baseVersion: 6, fields: { markKind: 'score', score: 14 } })
    await assertDisplayFlags(database)
  })

  it('coalesces a second edit into the one unsent entry and keeps the first shadow', async () => {
    await database.marks.put({ id: 'm1', version: 6, assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 12 })

    await writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1', score(14), 6)], 'Teacher', AT)
    await writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1', score(15), 6)], 'Teacher', LATER)

    const list = await entries('marks', 'm1')
    expect(list).toHaveLength(1)
    expect(list[0]).toMatchObject({ baseVersion: 6, fields: { markKind: 'score', score: 15 }, at: LATER })
    expect((await database.marks.get('m1'))?.serverShadow).toEqual({ markKind: 'score', score: 12 })
  })

  it('caps the base at the stored version, so a pull between render and write cannot rebase the edit', async () => {
    await database.marks.put({ id: 'm1', version: 4, assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 12 })

    await writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1', score(14), 9)], 'Teacher', AT)

    expect((await entries('marks', 'm1'))[0].baseVersion).toBe(4)
  })

  it('uses the stored version when the cell carries no base', async () => {
    await database.marks.put({ id: 'm1', version: 4, assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 12 })

    await writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1', score(14))], 'Teacher', AT)

    expect((await entries('marks', 'm1'))[0].baseVersion).toBe(4)
  })

  it('waits behind a sent entry without identity fields, and seeds nothing over a protected field', async () => {
    await writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1', score(9))], 'Teacher', AT)
    await database.outbox.where('[table+recordId]').equals(['marks', 'm1']).modify({ state: 'sent' })

    await writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1', score(10))], 'Teacher', LATER)

    const list = await entries('marks', 'm1')
    expect(list.map((entry) => [entry.state, entry.baseVersion])).toEqual([['sent', 0], ['queued', null]])
    expect(list[1].fields).toEqual({ markKind: 'score', score: 10 })
    expect(list[0].fields).toMatchObject({ score: 9 })
    expect(await database.marks.get('m1')).not.toHaveProperty('serverShadow')
    await assertDisplayFlags(database)
  })

  it('writes several cells in one transaction and none when one is refused', async () => {
    await database.marks.put({ id: 'm2', version: 3, assessmentId: 'a1', studentId: 's2', criterionId: 'k1', markKind: 'score', score: 4 })

    await expect(writeMarkCells(database, 'a1', [
      edit('m1', 's1', 'k1'),
      edit('m2', 's2', 'k1', score(1), Number.NaN),
    ], 'Teacher', AT)).rejects.toThrow()

    expect(await database.marks.get('m1')).toBeUndefined()
    expect(await database.marks.get('m2')).toMatchObject({ score: 4 })
    expect(await database.outbox.count()).toBe(0)
  })

  it('refuses an edit on a finalized assessment and writes nothing', async () => {
    await finalizeAssessmentRecord(database, 'a1', 'u1', AT)

    await expect(writeMarkCells(database, 'a1', [edit('m1', 's1', 'k1')], 'Teacher', LATER)).rejects.toThrow(/finalized/i)

    expect(await database.marks.count()).toBe(0)
    expect(await database.outbox.count()).toBe(1)
  })

  it('refuses an edit on an assessment that is not on this device', async () => {
    await expect(writeMarkCells(database, 'missing', [edit('m1', 's1', 'k1')], 'Teacher', AT)).rejects.toThrow()
  })
})

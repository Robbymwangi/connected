import 'fake-indexeddb/auto'
import Dexie from 'dexie'
import { describe, expect, it } from 'vitest'
import { assertDisplayFlags } from './displayFlags.testing'
import { LocalDatabase } from './localDatabase'

const CREATE = { classId: 'c1', subjectId: 'sub1', name: 'CAT 1', term: 'Term 1', year: 2025, date: '2025-05-12' }

/* A database as schema v2 left it: markers on the records and an empty outbox. */
async function seedLegacy(name: string) {
  const legacy = new Dexie(name)
  legacy.version(1).stores({
    assessments: '&id, classId, subjectId, createdBy',
    marks: '&id, assessmentId, studentId, criterionId, lastEditedBy',
    outbox: '++seq, &id, [table+recordId], state',
  })
  await legacy.table('assessments').bulkPut([
    { id: 'a1', version: 0, ...CREATE, status: 'scheduled', sync: 'pending', pendingBaseVersion: 0, pendingFields: CREATE },
    {
      id: 'a2', version: 3, name: 'Renamed', status: 'finalized', finalizedBy: 'u1', finalizedAt: 'now', sync: 'pending',
      pendingBaseVersion: 3, pendingFields: { name: 'Renamed', status: 'finalized', finalizedBy: 'u1', finalizedAt: 'now' },
    },
    { id: 'a3', version: 2, ...CREATE, status: 'scheduled' },
  ])
  await legacy.table('marks').bulkPut([
    {
      id: 'm1', version: 0, assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 9,
      sync: 'pending', pendingBaseVersion: 0,
      pendingFields: { assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 9 },
    },
    { id: 'm2', version: 3, assessmentId: 'a2', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 7 },
  ])
  legacy.close()
}

describe('schema v3 upgrade', () => {
  it('moves the markers into the outbox in send order and strips them from the records', async () => {
    const name = `connected-test-${crypto.randomUUID()}`
    await seedLegacy(name)

    const database = new LocalDatabase(name)
    await database.open()

    const entries = await database.outbox.orderBy('seq').toArray()
    expect(entries.map((entry) => [entry.table, entry.recordId, entry.kind, entry.baseVersion])).toEqual([
      ['assessments', 'a1', 'patch', 0],
      ['assessments', 'a2', 'patch', 3],
      ['marks', 'm1', 'patch', 0],
      ['assessments', 'a2', 'finalize', null],
    ])
    expect(entries.map((entry) => entry.seq)).toEqual([...entries.map((entry) => entry.seq)].sort((a, b) => a! - b!))

    for (const table of ['assessments', 'marks'] as const) {
      for (const record of await database.table(table).toArray()) {
        expect(record).not.toHaveProperty('pendingFields')
        expect(record).not.toHaveProperty('pendingBaseVersion')
      }
    }
    expect(await database.assessments.get('a3')).toMatchObject({ version: 2, name: 'CAT 1' })
    expect(await database.marks.get('m2')).toMatchObject({ version: 3, score: 7 })
    await assertDisplayFlags(database)

    database.close()
    await database.delete()
  })

  it('opens an already current database again without repeating the migration', async () => {
    const name = `connected-test-${crypto.randomUUID()}`
    await seedLegacy(name)

    const first = new LocalDatabase(name)
    await first.open()
    const count = await first.outbox.count()
    first.close()

    const second = new LocalDatabase(name)
    await second.open()

    expect(await second.outbox.count()).toBe(count)
    second.close()
    await second.delete()
  })
})

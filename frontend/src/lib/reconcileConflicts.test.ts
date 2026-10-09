import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it } from 'vitest'
import { assertDisplayFlags } from './displayFlags.testing'
import { LocalDatabase, type LocalRecord } from './localDatabase'
import type { OutboxEntry } from './outbox'
import { reconcileConflicts } from './reconcileConflicts'

const AT = '2026-10-09T08:00:00.000Z'
const stores: LocalDatabase[] = []

afterEach(async () => {
  for (const database of stores.splice(0)) {
    database.close()
    await database.delete()
  }
})

function createDatabase(): LocalDatabase {
  const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
  stores.push(database)
  return database
}

const unresolved = (over: Partial<LocalRecord> = {}): LocalRecord => ({ id: 'c1', version: 1, markId: 'm1', resolution: null, resolvedAt: null, ...over })
const resolved = (over: Partial<LocalRecord> = {}): LocalRecord => unresolved({
  version: 2, resolution: { kind: 'self', byId: 'u1' }, resolvedAt: '2026-10-09T09:00:00.000Z', ...over,
})

/* A mark whose edit was retained as a conflict, with the settled value the pull has
   since brought waiting in the shadow. */
const mark = (over: Partial<LocalRecord> = {}): LocalRecord => ({
  id: 'm1', version: 5, assessmentId: 'a1', markKind: 'score', score: 9, sync: 'pending', localAuthor: 'Me',
  serverShadow: { markKind: 'score', score: 12 }, serverShadowAt: { markKind: 5, score: 5 },
  ...over,
})

const entry = (over: Partial<OutboxEntry> = {}): OutboxEntry => ({
  id: crypto.randomUUID(), table: 'marks', recordId: 'm1', kind: 'patch', baseVersion: 3,
  fields: { markKind: 'score', score: 9 }, at: AT, state: 'conflict', conflictId: 'c1', ...over,
})

const command = (over: Partial<OutboxEntry> = {}): OutboxEntry => entry({
  table: 'conflicts', recordId: 'c1', kind: 'command', baseVersion: 1, conflictId: undefined,
  fields: { referral: { byId: 'u1' } }, state: 'acked', ackVersion: 2, ...over,
})

describe('acknowledged commands', () => {
  it.each([
    ['the conflict has reached the acknowledged version', unresolved({ version: 2 })],
    ['the conflict is past the acknowledged version', unresolved({ version: 3 })],
    ['the conflict is resolved', resolved({ version: 1 })],
  ])('are dropped once %s', async (_name, row) => {
    const database = createDatabase()
    await database.conflicts.put(row)
    await database.outbox.add(command())

    expect(await reconcileConflicts(database)).toEqual({ released: 0 })

    expect(await database.outbox.count()).toBe(0)
  })

  it('are dropped when their conflict is not on this device at all', async () => {
    const database = createDatabase()
    await database.outbox.add(command())

    await reconcileConflicts(database)

    expect(await database.outbox.count()).toBe(0)
  })

  it('are kept while the pull has not yet reached the version they were answered at', async () => {
    const database = createDatabase()
    await database.conflicts.put(unresolved({ version: 1 }))
    await database.outbox.add(command())

    await reconcileConflicts(database)

    expect(await database.outbox.count()).toBe(1)
  })

  it('leaves queued, sent, and failed commands alone', async () => {
    const database = createDatabase()
    await database.conflicts.put(resolved())
    await database.outbox.bulkAdd([
      command({ state: 'queued', ackVersion: undefined }),
      command({ state: 'sent', ackVersion: undefined }),
      command({ state: 'failed', ackVersion: undefined, reason: 'no' }),
    ])

    await reconcileConflicts(database)

    expect((await database.outbox.toArray()).map((item) => item.state)).toEqual(['queued', 'sent', 'failed'])
  })
})

describe('a mark entry retained for a conflict', () => {
  it('is released once the conflict has resolved: the entry goes, the cell takes the settled mark, the flag clears', async () => {
    const database = createDatabase()
    await database.conflicts.put(resolved())
    await database.marks.put(mark())
    await database.outbox.add(entry())

    expect(await reconcileConflicts(database)).toEqual({ released: 1 })

    expect(await database.outbox.count()).toBe(0)
    const record = await database.marks.get('m1')
    expect(record).toMatchObject({ version: 5, score: 12, markKind: 'score' })
    for (const key of ['serverShadow', 'serverShadowAt', 'sync', 'localAuthor']) expect(record).not.toHaveProperty(key)
    await assertDisplayFlags(database)
  })

  it('stays while its conflict is still open', async () => {
    const database = createDatabase()
    await database.conflicts.put(unresolved())
    await database.marks.put(mark())
    await database.outbox.add(entry())

    expect(await reconcileConflicts(database)).toEqual({ released: 0 })

    expect((await database.outbox.toArray())[0]).toMatchObject({ state: 'conflict', conflictId: 'c1' })
    expect(await database.marks.get('m1')).toMatchObject({ score: 9, sync: 'pending', serverShadow: { score: 12 } })
  })

  it('stays while its conflict has not been pulled yet', async () => {
    const database = createDatabase()
    await database.marks.put(mark())
    await database.outbox.add(entry())

    expect(await reconcileConflicts(database)).toEqual({ released: 0 })
    expect(await database.outbox.count()).toBe(1)
  })

  it('is released by an automatic resolution too, since the pulled row says only that it is resolved', async () => {
    const database = createDatabase()
    await database.conflicts.put(resolved({ resolution: { kind: 'auto' } }))
    await database.marks.put(mark())
    await database.outbox.add(entry())

    expect(await reconcileConflicts(database)).toEqual({ released: 1 })
  })

  it('hands the edit that was held behind it the version the resolution produced, and keeps the flag', async () => {
    const database = createDatabase()
    await database.conflicts.put(resolved())
    await database.marks.put(mark({ version: 7 }))
    const held = entry({ state: 'queued', baseVersion: null, conflictId: undefined, fields: { score: 10 } })
    await database.outbox.bulkAdd([entry(), held])

    await reconcileConflicts(database)

    const [remaining] = await database.outbox.toArray()
    expect(remaining).toMatchObject({ id: held.id, state: 'queued', baseVersion: 7, fields: { score: 10 } })
    expect(await database.marks.get('m1')).toMatchObject({ version: 7, sync: 'pending' })
    await assertDisplayFlags(database)
  })

  it('does not take a field back from an edit still held on it', async () => {
    const database = createDatabase()
    await database.conflicts.put(resolved())
    await database.marks.put(mark({ score: 10 }))
    await database.outbox.bulkAdd([entry(), entry({ state: 'queued', baseVersion: null, conflictId: undefined, fields: { score: 10 } })])

    await reconcileConflicts(database)

    expect(await database.marks.get('m1')).toMatchObject({ score: 10, markKind: 'score' })
  })

  it('releases each of several and counts them', async () => {
    const database = createDatabase()
    await database.conflicts.bulkPut([resolved(), resolved({ id: 'c2', markId: 'm2' })])
    await database.marks.bulkPut([mark(), mark({ id: 'm2' })])
    await database.outbox.bulkAdd([entry(), entry({ recordId: 'm2', conflictId: 'c2' })])

    expect(await reconcileConflicts(database)).toEqual({ released: 2 })
  })

  it('leaves an entry that is not a mark conflict alone', async () => {
    const database = createDatabase()
    await database.conflicts.put(resolved())
    await database.assessments.put({ id: 'a1', version: 3, name: 'x', sync: 'pending' })
    await database.outbox.add(entry({ table: 'assessments', recordId: 'a1', conflictId: undefined, fields: { name: 'x' } }))

    expect(await reconcileConflicts(database)).toEqual({ released: 0 })
    expect(await database.outbox.count()).toBe(1)
  })
})

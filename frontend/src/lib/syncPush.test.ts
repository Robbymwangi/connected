import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api/client'
import { assertDisplayFlags } from './displayFlags.testing'
import { LocalDatabase, type LocalRecord } from './localDatabase'
import type { OutboxEntry } from './outbox'
import { classifyPushFailure, pushSync, SyncPushProtocolError, syncNoticesKey } from './syncPush'
import { pullSync } from './syncPull'

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

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status })
}

type Wire = { id: string; table: string; recordId: string; baseVersion: number; fields: Record<string, unknown>; at: string }

/* A server stand-in: answers a POST /sync by calling `answer` with the entries sent. */
function server(answer: (entries: Wire[]) => unknown[]) {
  return vi.fn(async (_path: unknown, init?: RequestInit) => {
    const body = JSON.parse(String(init?.body)) as { entries: Wire[] }
    return json({ results: answer(body.entries) })
  })
}

const asFetch = (mock: unknown) => mock as unknown as typeof fetch
const accepted = (id: string, version: number, extra: object = {}) => ({ id, status: 'accepted', version, ...extra })

function entry(partial: Partial<OutboxEntry> & Pick<OutboxEntry, 'table' | 'recordId' | 'fields'>): OutboxEntry {
  return { id: crypto.randomUUID(), kind: 'patch', baseVersion: 1, at: AT, state: 'queued', ...partial }
}

function mark(over: Partial<LocalRecord> = {}): LocalRecord {
  return {
    id: 'm1', version: 6, assessmentId: 'a1', studentId: 's1', criterionId: 'k1',
    markKind: 'score', score: 9, sync: 'pending', localAuthor: 'Me',
    serverShadow: { markKind: 'score', score: 8 }, serverShadowAt: { markKind: 6, score: 6 },
    ...over,
  }
}

const markEdit = (over: Partial<OutboxEntry> = {}) =>
  entry({ table: 'marks', recordId: 'm1', baseVersion: 6, fields: { markKind: 'score', score: 9 }, ...over })

async function entries(database: LocalDatabase, recordId: string) {
  return (await database.outbox.orderBy('seq').toArray()).filter((item) => item.recordId === recordId)
}

describe('selection and freezing', () => {
  it('sends sent and queued entries in seq order, never a queued entry that has no base yet', async () => {
    const database = createDatabase()
    const a = markEdit({ recordId: 'mA', state: 'sent' })
    const b = markEdit({ recordId: 'mB' })
    const held = markEdit({ recordId: 'mB', baseVersion: null, fields: { score: 11 } })
    const c = markEdit({ recordId: 'mC' })
    await database.outbox.bulkAdd([a, b, held, c])
    const fetchMock = server((sent) => sent.map((item) => accepted(item.id, 7)))

    const result = await pushSync('token', 'u1', asFetch(fetchMock), database)

    const body = JSON.parse(String(fetchMock.mock.calls[0][1]?.body)) as { entries: Wire[] }
    expect(body.entries.map((item) => item.recordId)).toEqual(['mA', 'mB', 'mC'])
    expect(body.entries[0]).toEqual({
      id: a.id, table: 'marks', recordId: 'mA', baseVersion: 6, fields: { markKind: 'score', score: 9 }, at: AT,
    })
    expect(result).toMatchObject({ sent: 3, held: 1 })
    expect(String(fetchMock.mock.calls[0][0])).toBe('/api/sync')
    expect(fetchMock.mock.calls[0][1]?.method).toBe('POST')
  })

  it('sends at most the batch limit, oldest first, and leaves the rest queued', async () => {
    const database = createDatabase()
    await database.outbox.bulkAdd(Array.from({ length: 5 }, (_, i) => markEdit({ recordId: `m${i}` })))
    const fetchMock = server((sent) => sent.map((item) => accepted(item.id, 7)))

    await pushSync('token', 'u1', asFetch(fetchMock), database, 3)

    const body = JSON.parse(String(fetchMock.mock.calls[0][1]?.body)) as { entries: Wire[] }
    expect(body.entries.map((item) => item.recordId)).toEqual(['m0', 'm1', 'm2'])
    expect((await database.outbox.toArray()).map((item) => [item.recordId, item.state])).toEqual([
      ['m3', 'queued'], ['m4', 'queued'],
    ])
  })

  it('commits the entries as sent before it calls fetch', async () => {
    const database = createDatabase()
    await database.outbox.add(markEdit())
    const seen: string[] = []
    const fetchMock = vi.fn(async () => {
      seen.push(...(await database.outbox.toArray()).map((item) => item.state))
      return json({ results: [accepted((await database.outbox.toArray())[0].id, 7)] })
    })

    await pushSync('token', 'u1', asFetch(fetchMock), database)

    expect(seen).toEqual(['sent'])
  })

  it('does not call fetch when nothing is sendable', async () => {
    const database = createDatabase()
    await database.outbox.add(markEdit({ baseVersion: null }))
    const fetchMock = vi.fn()

    const result = await pushSync('token', 'u1', asFetch(fetchMock), database)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(result).toMatchObject({ sent: 0, held: 1 })
  })

  const finalizeEntry = () => entry({
    table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: 3,
    fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
  })

  it('holds a finalize while an earlier mark of its assessment is held', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 3, status: 'finalized', sync: 'pending' })
    await database.marks.put(mark({ id: 'mHeld' }))
    await database.outbox.bulkAdd([markEdit({ recordId: 'mHeld', baseVersion: null }), finalizeEntry()])
    const fetchMock = server((sent) => sent.map((item) => accepted(item.id, 7)))

    const result = await pushSync('token', 'u1', asFetch(fetchMock), database)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(result).toMatchObject({ sent: 0, held: 2 })
    expect((await database.outbox.toArray()).map((item) => item.state)).toEqual(['queued', 'queued'])
  })

  it('holds a finalize while a mark of its assessment is in conflict', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 3, status: 'finalized', sync: 'pending' })
    await database.marks.put(mark({ id: 'mConflict' }))
    await database.outbox.bulkAdd([markEdit({ recordId: 'mConflict', state: 'conflict' }), finalizeEntry()])
    const fetchMock = vi.fn()

    expect((await pushSync('token', 'u1', asFetch(fetchMock), database)).sent).toBe(0)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('sends a finalize in the same batch, behind the marks of its assessment, when none is held', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 3, status: 'finalized', sync: 'pending' })
    await database.marks.put(mark({ id: 'mFree' }))
    await database.outbox.bulkAdd([markEdit({ recordId: 'mFree' }), finalizeEntry()])
    const fetchMock = server((sent) => sent.map((item) => accepted(item.id, 7)))

    await pushSync('token', 'u1', asFetch(fetchMock), database)

    const body = JSON.parse(String(fetchMock.mock.calls[0][1]?.body)) as { entries: Wire[] }
    expect(body.entries.map((item) => item.recordId)).toEqual(['mFree', 'a1'])
  })
})

describe('an accepted or merged entry', () => {
  it.each(['accepted', 'merged'] as const)('settles on %s: drops the entry, raises the version, clears the shadow and the flags', async (status) => {
    const database = createDatabase()
    await database.marks.put(mark())
    const sent = markEdit()
    await database.outbox.add(sent)

    const result = await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({ id: item.id, status, version: 7 })))), database)

    expect(result.settled[status]).toBe(1)
    expect(await database.outbox.count()).toBe(0)
    const record = await database.marks.get('m1')
    expect(record).toMatchObject({ version: 7, ackedVersion: 7, score: 9 })
    for (const key of ['serverShadow', 'serverShadowAt', 'sync', 'localAuthor']) expect(record).not.toHaveProperty(key)
    await assertDisplayFlags(database)
  })

  it('rebases the held follow-up to the version the server answered, and keeps the flag and a shadow of the acked value', async () => {
    const database = createDatabase()
    await database.marks.put(mark({ score: 10 }))
    await database.outbox.bulkAdd([markEdit(), markEdit({ baseVersion: null, fields: { score: 10 } })])

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => accepted(item.id, 7)))), database)

    const [follow] = await entries(database, 'm1')
    expect(follow).toMatchObject({ baseVersion: 7, state: 'queued', fields: { score: 10 } })
    const record = await database.marks.get('m1')
    expect(record).toMatchObject({ version: 7, sync: 'pending', score: 10, serverShadow: { score: 9 }, serverShadowAt: { score: 7 } })
    expect(record?.serverShadow).not.toHaveProperty('markKind')
    await assertDisplayFlags(database)
  })

  it('sends the rebased follow-up on the next push', async () => {
    const database = createDatabase()
    await database.marks.put(mark({ score: 10 }))
    await database.outbox.bulkAdd([markEdit(), markEdit({ baseVersion: null, fields: { score: 10 } })])
    const fetchMock = server((e) => e.map((item) => accepted(item.id, 7 + Number(item.fields.score) - 9)))

    await pushSync('token', 'u1', asFetch(fetchMock), database)
    await pushSync('token', 'u1', asFetch(fetchMock), database)

    const second = JSON.parse(String(fetchMock.mock.calls[1][1]?.body)) as { entries: Wire[] }
    expect(second.entries).toMatchObject([{ recordId: 'm1', baseVersion: 7, fields: { score: 10 } }])
    expect(await database.outbox.count()).toBe(0)
    expect(await database.marks.get('m1')).toMatchObject({ version: 8 })
    await assertDisplayFlags(database)
  })

  it('lets the pull fill what only the server writes, at the acknowledged version', async () => {
    const database = createDatabase()
    await database.marks.put(mark())
    await database.outbox.add(markEdit())
    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => accepted(item.id, 7)))), database)

    const pullFetch = vi.fn().mockResolvedValue(json({
      changes: [{ table: 'marks', recordId: 'm1', version: 7, fields: { score: 9, lastEditedBy: 'u1' } }],
      cursor: 30, more: false,
    }))
    await database.metadata.put({ key: 'syncCursor:u1', value: 29 })
    const pulled = await pullSync('token', 'u1', asFetch(pullFetch), database)

    expect(pulled.changesApplied).toBe(1)
    const record = await database.marks.get('m1')
    expect(record).toMatchObject({ version: 7, lastEditedBy: 'u1' })
    expect(record).not.toHaveProperty('ackedVersion')
  })

  it('on a replayed answer older than a pulled version, keeps the newer server value and the local one it seeded', async () => {
    const database = createDatabase()
    await database.marks.put(mark({
      version: 9, score: 9,
      serverShadow: { markKind: 'score', score: 12 }, serverShadowAt: { markKind: 6, score: 9 },
    }))
    await database.outbox.add(markEdit({ state: 'sent' }))

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => accepted(item.id, 7, { replayed: true })))), database)

    const record = await database.marks.get('m1')
    expect(record).toMatchObject({ version: 9, score: 12, markKind: 'score' })
    expect(record).not.toHaveProperty('ackedVersion')
    expect(record).not.toHaveProperty('serverShadow')
    await assertDisplayFlags(database)
  })

  it('settles a finalize and a created assessment the same way', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 0, name: 'CAT', status: 'finalized', sync: 'pending' })
    await database.outbox.bulkAdd([
      entry({ table: 'assessments', recordId: 'a1', baseVersion: 0, fields: { name: 'CAT' } }),
      entry({
        table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: null,
        fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
      }),
    ])
    const fetchMock = server((e) => e.map((item) => accepted(item.id, item.fields.status ? 2 : 1)))

    await pushSync('token', 'u1', asFetch(fetchMock), database)
    expect(await database.assessments.get('a1')).toMatchObject({ version: 1, sync: 'pending' })
    expect((await database.outbox.toArray())[0]).toMatchObject({ kind: 'finalize', baseVersion: 1 })

    await pushSync('token', 'u1', asFetch(fetchMock), database)
    expect(await database.assessments.get('a1')).toMatchObject({ version: 2, status: 'finalized' })
    expect(await database.assessments.get('a1')).not.toHaveProperty('sync')
    await assertDisplayFlags(database)
  })
})

describe('a conflict', () => {
  const current = (fields: Record<string, unknown>, table = 'marks', recordId = 'm1', version = 7) => ({ table, recordId, version, fields })

  it('on a mark with a conflict id retains the entry, adopts the server row into the shadow, and keeps the flag', async () => {
    const database = createDatabase()
    await database.marks.put(mark())
    await database.outbox.bulkAdd([markEdit(), markEdit({ baseVersion: null, fields: { score: 10 } })])

    const result = await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({
      id: item.id, status: 'conflict', conflictId: 'c-1', current: current({ markKind: 'score', score: 6, lastEditedBy: 'u2' }),
    })))), database)

    expect(result.settled.conflict).toBe(1)
    const [kept, follow] = await entries(database, 'm1')
    expect(kept).toMatchObject({ state: 'conflict', conflictId: 'c-1', fields: { markKind: 'score', score: 9 } })
    expect(follow).toMatchObject({ state: 'queued', baseVersion: null })
    expect(await database.marks.get('m1')).toMatchObject({
      version: 7, score: 9, lastEditedBy: 'u2', sync: 'pending',
      serverShadow: { score: 6 }, serverShadowAt: { score: 7 },
    })
    await assertDisplayFlags(database)
  })

  it('on an assessment without a conflict id drops the entry, adopts the server row, rebases the follow-up, and leaves a notice', async () => {
    const database = createDatabase()
    await database.assessments.put({
      id: 'a1', version: 3, name: 'Mine', term: 'Term 1', sync: 'pending',
      serverShadow: { name: 'Old' }, serverShadowAt: { name: 3 },
    })
    const sent = entry({ table: 'assessments', recordId: 'a1', baseVersion: 3, fields: { name: 'Mine' } })
    await database.outbox.bulkAdd([sent, entry({ table: 'assessments', recordId: 'a1', baseVersion: null, fields: { term: 'Term 2' } })])

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({
      id: item.id, status: 'conflict', current: current({ name: 'Theirs', term: 'Term 1' }, 'assessments', 'a1', 4),
    })))), database)

    const [follow] = await entries(database, 'a1')
    expect(follow).toMatchObject({ baseVersion: 3, fields: { term: 'Term 2' } })
    expect(await database.assessments.get('a1')).toMatchObject({
      version: 4, name: 'Theirs', term: 'Term 1', sync: 'pending', serverShadow: { term: 'Term 1' },
    })
    const notices = (await database.metadata.get(syncNoticesKey))?.value as Array<Record<string, unknown>>
    expect(notices).toHaveLength(1)
    expect(notices[0]).toMatchObject({
      id: sent.id, table: 'assessments', recordId: 'a1', kind: 'conflict',
      sent: { name: 'Mine' }, current: { name: 'Theirs', term: 'Term 1' },
    })
    await assertDisplayFlags(database)
  })

  it('clears the flag when a dropped conflict leaves nothing open', async () => {
    const database = createDatabase()
    await database.marks.put(mark())
    await database.outbox.add(markEdit())

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({
      id: item.id, status: 'conflict', current: current({ markKind: 'absent', score: null }, 'marks', 'm1', 8),
    })))), database)

    expect(await database.outbox.count()).toBe(0)
    const record = await database.marks.get('m1')
    expect(record).toMatchObject({ version: 8, markKind: 'absent', score: null })
    expect(record).not.toHaveProperty('sync')
    await assertDisplayFlags(database)
  })

  describe('whose row is older than what a pull landed while the push was out', () => {
    const pulledAhead = () => mark({
      version: 9, score: 9, lastEditedBy: 'u3',
      serverShadow: { score: 12 }, serverShadowAt: { score: 9 },
    })
    const older = (extra: object = {}) => (id: string) => ({
      id, status: 'conflict', current: current({ score: 6, lastEditedBy: 'u2' }, 'marks', 'm1', 7), ...extra,
    })

    it('leaves the record and its newer shadow alone when the entry is retained', async () => {
      const database = createDatabase()
      await database.marks.put(pulledAhead())
      await database.outbox.add(markEdit({ state: 'sent' }))

      await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => older({ conflictId: 'c-1' })(item.id)))), database)

      expect((await database.outbox.toArray())[0]).toMatchObject({ state: 'conflict', conflictId: 'c-1' })
      expect(await database.marks.get('m1')).toMatchObject({
        version: 9, score: 9, lastEditedBy: 'u3', serverShadow: { score: 12 }, serverShadowAt: { score: 9 },
      })
      await assertDisplayFlags(database)
    })

    it('does not roll unprotected fields back when the entry is dropped, and adopts the newer server value for its own fields', async () => {
      const database = createDatabase()
      await database.marks.put(pulledAhead())
      await database.outbox.add(markEdit({ state: 'sent' }))

      await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => older()(item.id)))), database)

      expect(await database.outbox.count()).toBe(0)
      const record = await database.marks.get('m1')
      expect(record).toMatchObject({ version: 9, score: 12, lastEditedBy: 'u3' })
      for (const key of ['serverShadow', 'serverShadowAt', 'sync']) expect(record).not.toHaveProperty(key)
      expect(((await database.metadata.get(syncNoticesKey))?.value as unknown[])).toHaveLength(1)
      await assertDisplayFlags(database)
    })

    it('still applies a row at the same version as the record', async () => {
      const database = createDatabase()
      await database.marks.put(mark({ version: 7 }))
      await database.outbox.add(markEdit({ state: 'sent' }))

      await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => older({ conflictId: 'c-1' })(item.id)))), database)

      expect(await database.marks.get('m1')).toMatchObject({ version: 7, lastEditedBy: 'u2', serverShadow: { score: 6 } })
    })
  })

  it('answers a replayed conflict whose row is gone with a notice and no adoption', async () => {
    const database = createDatabase()
    await database.marks.put(mark())
    const sent = markEdit({ state: 'sent' })
    await database.outbox.add(sent)

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({
      id: item.id, status: 'conflict', current: null, replayed: true,
    })))), database)

    expect(await database.outbox.count()).toBe(0)
    const notices = (await database.metadata.get(syncNoticesKey))?.value as Array<Record<string, unknown>>
    expect(notices[0]).toMatchObject({ id: sent.id, current: null })
    /* Nothing to adopt, but the dropped entry's value must not stay behind unflagged. */
    const record = await database.marks.get('m1')
    expect(record).toMatchObject({ version: 6, score: 8 })
    expect(record).not.toHaveProperty('sync')
    await assertDisplayFlags(database)
  })

  it('does not repeat a notice for the same mutation', async () => {
    const database = createDatabase()
    const sent = markEdit({ state: 'sent' })
    await database.marks.put(mark())
    await database.metadata.put({ key: syncNoticesKey, value: [{ id: sent.id, table: 'marks', recordId: 'm1', kind: 'conflict', sent: {}, current: null, at: AT }] })
    await database.outbox.add(sent)

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({ id: item.id, status: 'conflict', current: null, replayed: true })))), database)

    expect((await database.metadata.get(syncNoticesKey))?.value).toHaveLength(1)
  })
})

describe('a refused entry', () => {
  it.each([['invalid', 'score exceeds criterion max'], ['forbidden', 'forbidden']])('on %s fails the entry with its reason and reverts the covered fields to the shadow', async (status, reason) => {
    const database = createDatabase()
    await database.marks.put(mark())
    await database.outbox.add(markEdit())

    const result = await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => (
      status === 'invalid' ? { id: item.id, status, reason } : { id: item.id, status }
    )))), database)

    expect(result.settled[status as 'invalid' | 'forbidden']).toBe(1)
    expect((await database.outbox.toArray())[0]).toMatchObject({ state: 'failed', reason })
    const record = await database.marks.get('m1')
    expect(record).toMatchObject({ version: 6, score: 8, markKind: 'score' })
    for (const key of ['serverShadow', 'sync', 'localAuthor']) expect(record).not.toHaveProperty(key)
    await assertDisplayFlags(database)
  })

  it('does not revert a field a later entry still covers, and rebases that follow-up to the refused base', async () => {
    const database = createDatabase()
    await database.marks.put(mark({ score: 10 }))
    await database.outbox.bulkAdd([markEdit(), markEdit({ baseVersion: null, fields: { score: 10 } })])

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({ id: item.id, status: 'invalid', reason: 'no' })))), database)

    const [failed, follow] = await entries(database, 'm1')
    expect(failed.state).toBe('failed')
    expect(follow).toMatchObject({ state: 'queued', baseVersion: 6 })
    expect(await database.marks.get('m1')).toMatchObject({ score: 10, markKind: 'score', sync: 'pending' })
    await assertDisplayFlags(database)
  })

  it('removes a created assessment the server refused and fails what waited on it, but not what was already sent', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 0, name: 'CAT', status: 'scheduled', sync: 'pending' })
    await database.marks.bulkPut([
      mark({ id: 'm1', version: 0, serverShadow: undefined, serverShadowAt: undefined }),
      mark({ id: 'm2', version: 0, serverShadow: undefined, serverShadowAt: undefined }),
      mark({ id: 'm3', version: 0, serverShadow: undefined, serverShadowAt: undefined }),
    ])
    const create = entry({ table: 'assessments', recordId: 'a1', baseVersion: 0, fields: { name: 'CAT' } })
    const finalize = entry({
      table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: null,
      fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
    })
    await database.outbox.bulkAdd([
      create,
      markEdit({ recordId: 'm1', baseVersion: 0 }),
      markEdit({ recordId: 'm2', baseVersion: 0 }),
      finalize,
      markEdit({ recordId: 'm3', baseVersion: 0, state: 'sent' }),
    ])

    const fetchMock = server((sent) => sent.map((item) => (
      item.recordId === 'a1' ? { id: item.id, status: 'invalid', reason: 'bad class' }
      : item.recordId === 'm1' ? { id: item.id, status: 'invalid', reason: 'assessmentId does not resolve' }
      : { id: item.id, status: 'accepted', version: 1 }
    )))
    await pushSync('token', 'u1', asFetch(fetchMock), database, 2)

    expect(await database.assessments.get('a1')).toBeUndefined()
    expect(await database.marks.get('m1')).toBeUndefined()
    const states = Object.fromEntries((await database.outbox.toArray()).map((item) => [`${item.recordId}:${item.kind}`, [item.state, item.reason]]))
    expect(states['a1:patch']).toEqual(['failed', 'bad class'])
    expect(states['a1:finalize']).toEqual(['failed', 'create was refused'])
    expect(states['m2:patch']).toEqual(['failed', 'parent create was refused'])
    expect(await database.marks.get('m2')).toBeUndefined()
    expect(states['m3:patch']).toEqual(['sent', undefined])
    expect(await database.marks.get('m3')).toBeDefined()
    await assertDisplayFlags(database)
  })

  it('fails the queued marks of a refused assessment create that were not in the batch', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 0, name: 'CAT', status: 'scheduled', sync: 'pending' })
    await database.marks.bulkPut([mark({ id: 'm1', version: 0, serverShadow: undefined, serverShadowAt: undefined })])
    await database.outbox.bulkAdd([
      entry({ table: 'assessments', recordId: 'a1', baseVersion: 0, fields: { name: 'CAT' } }),
      markEdit({ recordId: 'm1', baseVersion: 0 }),
    ])

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({ id: item.id, status: 'invalid', reason: 'bad class' })))), database, 1)

    expect(await database.assessments.get('a1')).toBeUndefined()
    expect(await database.marks.get('m1')).toBeUndefined()
    expect((await database.outbox.toArray()).map((item) => [item.recordId, item.state, item.reason])).toEqual([
      ['a1', 'failed', 'bad class'],
      ['m1', 'failed', 'parent create was refused'],
    ])
    await assertDisplayFlags(database)
  })
})

describe('the response', () => {
  const sendOne = async (reply: (id: string) => unknown) => {
    const database = createDatabase()
    await database.marks.put(mark())
    const sent = markEdit()
    await database.outbox.add(sent)
    const fetchMock = vi.fn().mockResolvedValue(json(reply(sent.id)))
    const outcome = await pushSync('token', 'u1', asFetch(fetchMock), database).then(() => null, (error: unknown) => error)
    return { database, sent, outcome }
  }

  it.each([
    ['too few results', () => ({ results: [] })],
    ['too many results', (id: string) => ({ results: [accepted(id, 7), accepted(id, 8)] })],
    ['an id that is not the entry\'s', () => ({ results: [accepted('someone-else', 7)] })],
    ['an unknown status', (id: string) => ({ results: [{ id, status: 'maybe' }] })],
    ['a version of zero', (id: string) => ({ results: [accepted(id, 0)] })],
    ['a version below the base', (id: string) => ({ results: [accepted(id, 5)] })],
    ['a fractional version', (id: string) => ({ results: [accepted(id, 7.5)] })],
    ['a conflict without a current row', (id: string) => ({ results: [{ id, status: 'conflict' }] })],
    ['a current row for another record', (id: string) => ({ results: [{ id, status: 'conflict', current: { table: 'marks', recordId: 'other', version: 7, fields: {} } }] })],
    ['a current row that is not an object', (id: string) => ({ results: [{ id, status: 'conflict', current: 'row' }] })],
    ['an empty conflict id', (id: string) => ({ results: [{ id, status: 'conflict', conflictId: '', current: { table: 'marks', recordId: 'm1', version: 7, fields: {} } }] })],
    ['an invalid result without a reason', (id: string) => ({ results: [{ id, status: 'invalid' }] })],
    ['a replayed flag that is not true', (id: string) => ({ results: [{ ...accepted(id, 7), replayed: false }] })],
    ['no results key', () => ({})],
    ['a body that is not an object', () => 'results'],
  ])('rejects %s, applying nothing and leaving the entry sent', async (_name, reply) => {
    const { database, sent, outcome } = await sendOne(reply)

    expect(outcome).toBeInstanceOf(SyncPushProtocolError)
    expect(await database.outbox.toArray()).toEqual([{ ...sent, seq: expect.any(Number), state: 'sent' }])
    expect(await database.marks.get('m1')).toMatchObject({ version: 6, score: 9, sync: 'pending' })
  })

  it('rejects a conflict id on a table that does not raise conflict records', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 3, name: 'x', sync: 'pending' })
    const sent = entry({ table: 'assessments', recordId: 'a1', baseVersion: 3, fields: { name: 'x' } })
    await database.outbox.add(sent)
    const fetchMock = vi.fn().mockResolvedValue(json({ results: [{
      id: sent.id, status: 'conflict', conflictId: 'c-1', current: { table: 'assessments', recordId: 'a1', version: 4, fields: {} },
    }] }))

    await expect(pushSync('token', 'u1', asFetch(fetchMock), database)).rejects.toBeInstanceOf(SyncPushProtocolError)
    expect((await database.outbox.toArray())[0].state).toBe('sent')
  })
})

describe('a conflict command', () => {
  const referral = { referral: { byId: 'u1' } }
  const proposal = { proposal: { byId: 'u1', choice: { kind: 'side', editId: 'e1' }, note: 'mine is right' } }
  const command = (over: Partial<OutboxEntry> = {}) => entry({
    table: 'conflicts', recordId: 'c1', kind: 'command', baseVersion: 1, fields: referral, ...over,
  })
  const conflictRow = (over: Partial<LocalRecord> = {}): LocalRecord => ({
    id: 'c1', version: 1, markId: 'm1', proposals: [], referral: null, resolution: null, resolvedAt: null, ...over,
  })
  const staleCurrent = (version = 3, fields: Record<string, unknown> = { proposals: [{ byId: 'u2' }] }) => (
    { table: 'conflicts', recordId: 'c1', version, fields }
  )

  it('is sent in order with the other entries, in the wire shape, and a queued one with no base waits', async () => {
    const database = createDatabase()
    await database.conflicts.put(conflictRow())
    await database.marks.put(mark())
    const sent = command({ fields: proposal })
    await database.outbox.bulkAdd([markEdit(), sent, command({ baseVersion: null, fields: referral })])
    const fetchMock = server((entries) => entries.map((item) => accepted(item.id, 7)))

    const result = await pushSync('token', 'u1', asFetch(fetchMock), database)

    const body = JSON.parse(String(fetchMock.mock.calls[0][1]?.body)) as { entries: Wire[] }
    expect(body.entries.map((item) => [item.table, item.recordId])).toEqual([['marks', 'm1'], ['conflicts', 'c1']])
    expect(body.entries[1]).toEqual({ id: sent.id, table: 'conflicts', recordId: 'c1', baseVersion: 1, fields: proposal, at: AT })
    expect(result).toMatchObject({ sent: 2, held: 1 })
  })

  it.each([['accepted'], ['replayed']])('settles as %s: the command is kept as acknowledged, the conflict is untouched, and the next waits no longer', async (kind) => {
    const database = createDatabase()
    await database.conflicts.put(conflictRow())
    const first = command({ fields: proposal })
    await database.outbox.bulkAdd([first, command({ baseVersion: null, fields: referral })])

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => accepted(item.id, 2, kind === 'replayed' ? { replayed: true } : {})))), database)

    const [done, next] = await database.outbox.orderBy('seq').toArray()
    expect(done).toMatchObject({ id: first.id, state: 'acked', ackVersion: 2 })
    expect(next).toMatchObject({ state: 'queued', baseVersion: 2, fields: referral })
    expect(await database.conflicts.get('c1')).toEqual(conflictRow())
    await assertDisplayFlags(database)
  })

  it('treats merged as a protocol error, since a command is never merged', async () => {
    const database = createDatabase()
    await database.conflicts.put(conflictRow())
    await database.outbox.add(command())

    await expect(pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({ id: item.id, status: 'merged', version: 2 })))), database))
      .rejects.toBeInstanceOf(SyncPushProtocolError)

    expect((await database.outbox.toArray())[0].state).toBe('sent')
  })

  describe('answered with a stale base', () => {
    const stale = (current: unknown, extra: object = {}) => server((e) => e.map((item) => ({ id: item.id, status: 'conflict', current, ...extra })))

    it('is dropped with a notice, the fresh conflict is adopted, and the commands queued behind it are dropped too', async () => {
      const database = createDatabase()
      await database.conflicts.put(conflictRow())
      const first = command({ fields: proposal })
      const behind = command({ baseVersion: null, fields: referral })
      await database.outbox.bulkAdd([first, behind])

      const result = await pushSync('token', 'u1', asFetch(stale(staleCurrent())), database)

      expect(result.settled.conflict).toBe(1)
      expect(await database.outbox.count()).toBe(0)
      expect(await database.conflicts.get('c1')).toMatchObject({ version: 3, proposals: [{ byId: 'u2' }] })
      const notices = (await database.metadata.get(syncNoticesKey))?.value as Array<Record<string, unknown>>
      expect(notices.map((notice) => [notice.id, notice.table, notice.recordId])).toEqual([
        [first.id, 'conflicts', 'c1'], [behind.id, 'conflicts', 'c1'],
      ])
      expect(notices[0]).toMatchObject({ sent: proposal, current: { proposals: [{ byId: 'u2' }] } })
      await assertDisplayFlags(database)
    })

    it('does not roll the conflict back when a pull has already brought a newer version', async () => {
      const database = createDatabase()
      await database.conflicts.put(conflictRow({ version: 5, proposals: [{ byId: 'u3' }] }))
      await database.outbox.add(command({ state: 'sent' }))

      await pushSync('token', 'u1', asFetch(stale(staleCurrent(3))), database)

      expect(await database.conflicts.get('c1')).toMatchObject({ version: 5, proposals: [{ byId: 'u3' }] })
      expect(await database.outbox.count()).toBe(0)
    })

    it('adopts nothing from a replayed answer whose row is gone, but still drops the command with a notice', async () => {
      const database = createDatabase()
      await database.conflicts.put(conflictRow())
      await database.outbox.add(command({ state: 'sent' }))

      await pushSync('token', 'u1', asFetch(stale(null, { replayed: true })), database)

      expect(await database.conflicts.get('c1')).toEqual(conflictRow())
      expect(await database.outbox.count()).toBe(0)
      expect(((await database.metadata.get(syncNoticesKey))?.value as unknown[])).toHaveLength(1)
    })
  })

  it.each([
    ['invalid', 'a note is required', { reason: 'a note is required' }],
    ['forbidden', 'forbidden', {}],
  ])('fails on %s with its reason, and fails the commands queued behind it', async (status, reason, extra) => {
    const database = createDatabase()
    await database.conflicts.put(conflictRow())
    await database.outbox.bulkAdd([command({ fields: proposal }), command({ baseVersion: null, fields: referral })])

    await pushSync('token', 'u1', asFetch(server((e) => e.map((item) => ({ id: item.id, status, ...extra })))), database)

    const [first, behind] = await database.outbox.orderBy('seq').toArray()
    expect(first).toMatchObject({ state: 'failed', reason })
    expect(behind).toMatchObject({ state: 'failed', reason: 'an earlier action on this conflict was refused' })
    expect(await database.conflicts.get('c1')).toEqual(conflictRow())
    await assertDisplayFlags(database)
  })

  it('holds a finalize while a command on a mark of its assessment is held back', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 3, status: 'finalized', sync: 'pending' })
    await database.marks.put(mark({ id: 'm1', sync: undefined, localAuthor: undefined, serverShadow: undefined, serverShadowAt: undefined }))
    await database.conflicts.put(conflictRow())
    await database.outbox.bulkAdd([
      command({ baseVersion: null }),
      entry({
        table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: 3,
        fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
      }),
    ])
    const fetchMock = vi.fn()

    expect((await pushSync('token', 'u1', asFetch(fetchMock), database)).sent).toBe(0)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('holds every finalize behind a held command whose assessment cannot be worked out, since it might be theirs', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 3, status: 'finalized', sync: 'pending' })
    await database.outbox.bulkAdd([
      command({ recordId: 'c-not-on-this-device', baseVersion: null }),
      entry({
        table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: 3,
        fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
      }),
    ])
    const fetchMock = vi.fn()

    expect((await pushSync('token', 'u1', asFetch(fetchMock), database)).sent).toBe(0)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('does the same when the conflict is here but its mark is not', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 3, status: 'finalized', sync: 'pending' })
    await database.conflicts.put(conflictRow({ markId: 'mark-not-here' }))
    await database.outbox.bulkAdd([
      command({ baseVersion: null }),
      entry({
        table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: 3,
        fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
      }),
    ])

    expect((await pushSync('token', 'u1', asFetch(vi.fn()), database)).sent).toBe(0)
  })

  it('does not hold a finalize behind a held command that is known to belong to another assessment', async () => {
    const database = createDatabase()
    await database.assessments.bulkPut([
      { id: 'a1', version: 3, status: 'finalized', sync: 'pending' },
      { id: 'a2', version: 3, status: 'scheduled' },
    ])
    await database.marks.put(mark({ id: 'm2', assessmentId: 'a2', sync: undefined, localAuthor: undefined, serverShadow: undefined, serverShadowAt: undefined }))
    await database.conflicts.put(conflictRow({ markId: 'm2' }))
    await database.outbox.bulkAdd([
      command({ baseVersion: null }),
      entry({
        table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: 3,
        fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
      }),
    ])
    const fetchMock = server((e) => e.map((item) => accepted(item.id, 4)))

    await pushSync('token', 'u1', asFetch(fetchMock), database)

    const body = JSON.parse(String(fetchMock.mock.calls[0][1]?.body)) as { entries: Wire[] }
    expect(body.entries.map((item) => item.recordId)).toEqual(['a1'])
  })

  it('goes out ahead of a finalize on its assessment in the same batch when it is sendable', async () => {
    const database = createDatabase()
    await database.assessments.put({ id: 'a1', version: 3, status: 'finalized', sync: 'pending' })
    await database.marks.put(mark({ id: 'm1', sync: undefined, localAuthor: undefined, serverShadow: undefined, serverShadowAt: undefined }))
    await database.conflicts.put(conflictRow())
    await database.outbox.bulkAdd([
      command(),
      entry({
        table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: 3,
        fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
      }),
    ])
    const fetchMock = server((e) => e.map((item) => accepted(item.id, 4)))

    await pushSync('token', 'u1', asFetch(fetchMock), database)

    const body = JSON.parse(String(fetchMock.mock.calls[0][1]?.body)) as { entries: Wire[] }
    expect(body.entries.map((item) => item.table)).toEqual(['conflicts', 'assessments'])
  })
})

describe('request failures', () => {
  it('leaves every entry sent, and the resend carries the same body', async () => {
    const database = createDatabase()
    await database.marks.put(mark())
    const sent = markEdit()
    await database.outbox.add(sent)
    const lost = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(pushSync('token', 'u1', asFetch(lost), database)).rejects.toBeInstanceOf(TypeError)
    expect((await database.outbox.toArray())[0].state).toBe('sent')

    const retry = server((e) => e.map((item) => accepted(item.id, 7, { replayed: true })))
    await pushSync('token', 'u1', asFetch(retry), database)

    expect(retry.mock.calls[0][1]?.body).toBe(lost.mock.calls[0][1]?.body)
    expect(await database.outbox.count()).toBe(0)
    await assertDisplayFlags(database)
  })

  it('after a 5xx resends the whole batch and settles the replays normally', async () => {
    const database = createDatabase()
    await database.marks.bulkPut([mark({ id: 'mA' }), mark({ id: 'mB' })])
    await database.outbox.bulkAdd([markEdit({ recordId: 'mA' }), markEdit({ recordId: 'mB' })])
    const failing = vi.fn().mockResolvedValue(json({ message: 'boom' }, 500))

    await expect(pushSync('token', 'u1', asFetch(failing), database)).rejects.toBeInstanceOf(ApiError)
    const retry = server((e) => e.map((item, i) => accepted(item.id, 7, i === 0 ? { replayed: true } : {})))
    const result = await pushSync('token', 'u1', asFetch(retry), database)

    expect(result).toMatchObject({ sent: 2, settled: { accepted: 2 } })
    expect(await database.outbox.count()).toBe(0)
  })

  it.each([
    [new TypeError('Failed to fetch'), 'retry'],
    [new DOMException('aborted', 'AbortError'), 'retry'],
    [new ApiError(500, null), 'retry'],
    [new ApiError(503, null), 'retry'],
    [new ApiError(429, null), 'retry'],
    [new ApiError(401, null), 'reauth'],
    [new ApiError(403, null), 'defect'],
    [new ApiError(422, null), 'defect'],
    [new ApiError(404, null), 'defect'],
    [new SyncPushProtocolError('bad'), 'defect'],
    [new Error('other'), 'defect'],
  ])('classifies %s as %s', (error, expected) => {
    expect(classifyPushFailure(error)).toBe(expected)
  })
})

describe('overlapping runs', () => {
  it('shares one request between two calls on a database', async () => {
    const database = createDatabase()
    await database.marks.put(mark())
    await database.outbox.add(markEdit())
    const fetchMock = server((e) => e.map((item) => accepted(item.id, 7)))

    const [first, second] = await Promise.all([
      pushSync('token', 'u1', asFetch(fetchMock), database),
      pushSync('token', 'u1', asFetch(fetchMock), database),
    ])

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(first).toEqual(second)
  })

  it('ignores a result for an entry that is no longer there', async () => {
    const database = createDatabase()
    await database.marks.put(mark())
    const sent = markEdit()
    await database.outbox.add(sent)
    const fetchMock = vi.fn(async () => {
      await database.outbox.clear()
      return json({ results: [accepted(sent.id, 7)] })
    })

    await expect(pushSync('token', 'u1', asFetch(fetchMock), database)).resolves.toMatchObject({ sent: 1 })

    expect(await database.marks.get('m1')).toMatchObject({ version: 6, sync: 'pending' })
  })
})

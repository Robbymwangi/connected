import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { assertDisplayFlags } from './displayFlags.testing'
import { LocalDatabase, type LocalRecord, type SyncTableName } from './localDatabase'
import type { OutboxEntry } from './outbox'
import { pullSync, syncCursorKey } from './syncPull'

type Store = {
  database: LocalDatabase
  transaction: ReturnType<typeof vi.spyOn>
  seed: (table: SyncTableName, record: LocalRecord) => Promise<void>
  row: (table: SyncTableName, id: string) => Promise<LocalRecord | undefined>
  setCursor: (userId: string, value: number) => Promise<void>
  cursor: (userId: string) => Promise<unknown>
}

const stores: LocalDatabase[] = []

afterEach(async () => {
  for (const database of stores.splice(0)) {
    database.close()
    await database.delete()
  }
})

function createDatabase(): Store {
  const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
  stores.push(database)
  return {
    database,
    transaction: vi.spyOn(database, 'transaction'),
    seed: async (table, record) => { await database.table(table).put(record) },
    row: (table, id) => database.table(table).get(id),
    setCursor: async (userId, value) => { await database.metadata.put({ key: syncCursorKey(userId), value }) },
    cursor: async (userId) => (await database.metadata.get(syncCursorKey(userId)))?.value,
  }
}

function entry(partial: Partial<OutboxEntry> & Pick<OutboxEntry, 'table' | 'recordId' | 'fields'>): OutboxEntry {
  return { id: crypto.randomUUID(), kind: 'patch', baseVersion: 1, at: '2026-10-09T08:00:00.000Z', state: 'queued', ...partial }
}

function json(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200 })
}

describe('pullSync', () => {
  it('applies paged bootstrap rows and commits each page cursor with its records', async () => {
    const store = createDatabase()
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        json({
          changes: [{ table: 'students', recordId: 's1', version: 1, fields: { name: 'Amina', gender: 'female' } }],
          cursor: 'continuation-token',
          more: true,
        }),
      )
      .mockResolvedValueOnce(
        json({
          changes: [
            { table: 'students', recordId: 's1', version: 2, fields: { name: 'Amina W.' } },
            { table: 'assessments', recordId: 'a1', version: 1, fields: { name: 'Term test', status: 'scheduled' } },
          ],
          cursor: 42,
          more: false,
        }),
      )

    const result = await pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)

    expect(result).toEqual({ pages: 2, changesApplied: 3, cursor: 42 })
    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      '/api/sync?limit=500',
      '/api/sync?limit=500&since=continuation-token',
    ])
    expect(store.transaction).toHaveBeenCalledTimes(2)
    expect(await store.row('students', 's1')).toEqual({
      id: 's1',
      version: 2,
      name: 'Amina W.',
      gender: 'female',
    })
    expect(await store.row('assessments', 'a1')).toMatchObject({ id: 'a1', version: 1 })
    expect(await store.cursor('user-1')).toBe(42)
    expect(await store.cursor('user-2')).toBeUndefined()
  })

  it('resumes from the account cursor, skips stale versions, and preserves soft-deleted rows', async () => {
    const store = createDatabase()
    await store.setCursor('user-1', 18)
    await store.seed('marks', { id: 'm1', version: 2, score: 7, markKind: 'score' })
    const fetchMock = vi.fn().mockResolvedValue(
      json({
        changes: [
          { table: 'marks', recordId: 'm1', version: 1, fields: { score: 4 } },
          { table: 'marks', recordId: 'm1', version: 2, fields: { score: 9 } },
          { table: 'marks', recordId: 'm1', version: 3, fields: { markKind: 'absent', score: null, deletedAt: '2026-10-07T10:00:00Z' } },
        ],
        cursor: 22,
        more: false,
      }),
    )

    const result = await pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)

    expect(String(fetchMock.mock.calls[0][0])).toBe('/api/sync?limit=500&since=18')
    expect(result).toEqual({ pages: 1, changesApplied: 1, cursor: 22 })
    expect(await store.row('marks', 'm1')).toEqual({
      id: 'm1',
      version: 3,
      score: null,
      markKind: 'absent',
      deletedAt: '2026-10-07T10:00:00Z',
    })
    expect(await store.cursor('user-1')).toBe(22)
  })

  describe('fields the outbox protects', () => {
    const pull = (store: Store, changes: unknown[], cursor = 23) => {
      const fetchMock = vi.fn().mockResolvedValue(json({ changes, cursor, more: false }))
      return pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)
    }
    const markRow = { id: 'm1', version: 2, assessmentId: 'a1', studentId: 's1', criterionId: 'c1', markKind: 'score', score: 9, sync: 'pending', localAuthor: 'Me' }
    const mark = (fields: Record<string, unknown>, version = 3) => ({ table: 'marks', recordId: 'm1', version, fields })

    it('keeps the local value of a protected field, lands the server value in the shadow, and advances the version', async () => {
      const store = createDatabase()
      await store.setCursor('user-1', 22)
      await store.seed('marks', { ...markRow, serverShadow: { score: 8 } })
      await store.database.outbox.add(entry({ table: 'marks', recordId: 'm1', baseVersion: 2, fields: { markKind: 'score', score: 9 } }))

      await pull(store, [mark({ score: 6, lastEditedBy: 'teacher-2' })])

      expect(await store.row('marks', 'm1')).toMatchObject({
        version: 3, score: 9, lastEditedBy: 'teacher-2', sync: 'pending', localAuthor: 'Me', serverShadow: { score: 6 },
      })
      await assertDisplayFlags(store.database)
    })

    it('protects the fields of a finalize entry and of a sent or conflicted entry', async () => {
      const store = createDatabase()
      await store.seed('assessments', { id: 'a1', version: 2, status: 'finalized', name: 'Mine', sync: 'pending' })
      await store.database.outbox.bulkAdd([
        entry({ table: 'assessments', recordId: 'a1', kind: 'finalize', baseVersion: 2, state: 'sent', fields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: 'now' } }),
        entry({ table: 'assessments', recordId: 'a1', baseVersion: null, state: 'conflict', fields: { name: 'Mine' } }),
      ])

      await pull(store, [{ table: 'assessments', recordId: 'a1', version: 3, fields: { status: 'scheduled', name: 'Theirs', term: 'Term 2' } }])

      expect(await store.row('assessments', 'a1')).toMatchObject({
        version: 3, status: 'finalized', name: 'Mine', term: 'Term 2',
        serverShadow: { status: 'scheduled', name: 'Theirs' },
      })
    })

    it('writes an unprotected field through and clears its stale shadow key', async () => {
      const store = createDatabase()
      await store.seed('marks', { ...markRow, serverShadow: { score: 8, lastEditedBy: 'old' } })
      await store.database.outbox.add(entry({ table: 'marks', recordId: 'm1', baseVersion: 2, fields: { score: 9 } }))

      await pull(store, [mark({ lastEditedBy: 'teacher-2' })])

      const record = await store.row('marks', 'm1')
      expect(record).toMatchObject({ lastEditedBy: 'teacher-2', serverShadow: { score: 8 } })
      expect(record?.serverShadow).not.toHaveProperty('lastEditedBy')
    })

    it('drops the shadow when nothing is protected any more', async () => {
      const store = createDatabase()
      await store.seed('marks', { id: 'm1', version: 2, score: 9, serverShadow: { score: 8 } })

      await pull(store, [mark({ score: 6 })])

      const record = await store.row('marks', 'm1')
      expect(record).toMatchObject({ version: 3, score: 6 })
      expect(record).not.toHaveProperty('serverShadow')
    })

    it('is not protected by acknowledged, failed, or command entries', async () => {
      const store = createDatabase()
      await store.seed('marks', { id: 'm1', version: 2, score: 9 })
      await store.database.outbox.bulkAdd([
        entry({ table: 'marks', recordId: 'm1', state: 'failed', fields: { score: 9 } }),
        entry({ table: 'marks', recordId: 'm1', state: 'acked', kind: 'command', fields: { score: 9 } }),
      ])

      await pull(store, [mark({ score: 6 })])

      expect(await store.row('marks', 'm1')).toMatchObject({ version: 3, score: 6 })
    })

    it('applies a delete over a protected field', async () => {
      const store = createDatabase()
      await store.seed('marks', { ...markRow })
      await store.database.outbox.add(entry({ table: 'marks', recordId: 'm1', baseVersion: 2, fields: { score: 9, deletedAt: null } }))

      await pull(store, [mark({ deletedAt: '2026-10-09T09:00:00Z' })])

      expect(await store.row('marks', 'm1')).toMatchObject({ version: 3, deletedAt: '2026-10-09T09:00:00Z' })
    })

    it('does not move a record it skips as stale', async () => {
      const store = createDatabase()
      await store.seed('marks', { ...markRow, version: 4, serverShadow: { score: 8 } })
      await store.database.outbox.add(entry({ table: 'marks', recordId: 'm1', baseVersion: 4, fields: { score: 9 } }))

      const result = await pull(store, [mark({ score: 6 }, 3)])

      expect(result.changesApplied).toBe(0)
      expect(await store.row('marks', 'm1')).toMatchObject({ version: 4, score: 9, serverShadow: { score: 8 } })
    })
  })

  it('accepts numeric cursors across incremental log pages', async () => {
    const store = createDatabase()
    await store.setCursor('user-1', 18)
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        json({ changes: [{ table: 'students', recordId: 's1', version: 1, fields: { name: 'Amina' } }], cursor: 19, more: true }),
      )
      .mockResolvedValueOnce(
        json({ changes: [{ table: 'students', recordId: 's2', version: 1, fields: { name: 'Baraka' } }], cursor: 20, more: false }),
      )

    const result = await pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)

    expect(result).toEqual({ pages: 2, changesApplied: 2, cursor: 20 })
    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      '/api/sync?limit=500&since=18',
      '/api/sync?limit=500&since=19',
    ])
  })

  it('shares one in-flight pull for a database', async () => {
    const store = createDatabase()
    let finishResponse!: (response: Response) => void
    const pendingResponse = new Promise<Response>((resolve) => { finishResponse = resolve })
    const fetchMock = vi.fn(() => pendingResponse)

    const first = pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)
    const second = pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)
    finishResponse?.(json({ changes: [], cursor: 19, more: false }))

    await expect(Promise.all([first, second])).resolves.toEqual([
      { pages: 1, changesApplied: 0, cursor: 19 },
      { pages: 1, changesApplied: 0, cursor: 19 },
    ])
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(store.transaction).toHaveBeenCalledTimes(1)
  })

  it('rejects a numeric continuation cursor during bootstrap', async () => {
    const store = createDatabase()
    const fetchMock = vi.fn().mockResolvedValue(
      json({ changes: [], cursor: 1, more: true }),
    )

    await expect(pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)).rejects.toThrow(
      'GET /sync bootstrap continuation cursor must be opaque',
    )
    expect(store.transaction).not.toHaveBeenCalled()
  })

  it('rejects an opaque continuation cursor during an incremental pull', async () => {
    const store = createDatabase()
    await store.setCursor('user-1', 18)
    const fetchMock = vi.fn().mockResolvedValue(
      json({ changes: [], cursor: 'continuation-token', more: true }),
    )

    await expect(pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)).rejects.toThrow(
      'GET /sync incremental cursor must be numeric',
    )
    expect(store.transaction).not.toHaveBeenCalled()
  })

  it('rejects unknown tables without advancing the cursor', async () => {
    const store = createDatabase()
    const fetchMock = vi.fn().mockResolvedValue(
      json({
        changes: [{ table: 'institutions', recordId: 'i1', version: 1, fields: { name: 'School' } }],
        cursor: 2,
        more: false,
      }),
    )

    await expect(pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)).rejects.toThrow(
      'Invalid GET /sync change',
    )
    expect(store.transaction).not.toHaveBeenCalled()
    expect(await store.cursor('user-1')).toBeUndefined()
  })
})
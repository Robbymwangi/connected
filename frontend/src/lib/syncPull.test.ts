import { describe, expect, it, vi } from 'vitest'
import type { LocalDatabase, LocalRecord, SyncTableName } from './localDatabase'
import { pullSync, syncCursorKey } from './syncPull'

type FakeDatabase = {
  database: LocalDatabase
  rows: Map<SyncTableName, Map<string, LocalRecord>>
  metadata: Map<string, { key: string; value: unknown }>
  transaction: ReturnType<typeof vi.fn>
}

function createDatabase(): FakeDatabase {
  const rows = new Map<SyncTableName, Map<string, LocalRecord>>()
  const metadata = new Map<string, { key: string; value: unknown }>()
  const transaction = vi.fn(async (_mode: string, _tables: unknown, run: () => Promise<void>) => run())
  const database = {
    table(name: SyncTableName) {
      let records = rows.get(name)
      if (!records) {
        records = new Map()
        rows.set(name, records)
      }

      return {
        get: vi.fn(async (id: string) => records?.get(id)),
        put: vi.fn(async (record: LocalRecord) => {
          records?.set(record.id, record)
          return record.id
        }),
      }
    },
    metadata: {
      get: vi.fn(async (key: string) => metadata.get(key)),
      put: vi.fn(async (record: { key: string; value: unknown }) => {
        metadata.set(record.key, record)
        return record.key
      }),
    },
    transaction,
  } as unknown as LocalDatabase

  return { database, rows, metadata, transaction }
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
    expect(store.rows.get('students')?.get('s1')).toEqual({
      id: 's1',
      version: 2,
      name: 'Amina W.',
      gender: 'female',
    })
    expect(store.rows.get('assessments')?.get('a1')).toMatchObject({ id: 'a1', version: 1 })
    expect(store.metadata.get(syncCursorKey('user-1'))?.value).toBe(42)
    expect(store.metadata.has(syncCursorKey('user-2'))).toBe(false)
  })

  it('resumes from the account cursor, skips stale versions, and preserves soft-deleted rows', async () => {
    const store = createDatabase()
    store.metadata.set(syncCursorKey('user-1'), { key: syncCursorKey('user-1'), value: 18 })
    store.rows.set('marks', new Map([['m1', { id: 'm1', version: 2, score: 7, markKind: 'score' }]]))
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
    expect(store.rows.get('marks')?.get('m1')).toEqual({
      id: 'm1',
      version: 3,
      score: null,
      markKind: 'absent',
      deletedAt: '2026-10-07T10:00:00Z',
    })
    expect(store.metadata.get(syncCursorKey('user-1'))?.value).toBe(22)
  })

  it('preserves a pending mark patch while merging newer server fields and version', async () => {
    const store = createDatabase()
    store.metadata.set(syncCursorKey('user-1'), { key: syncCursorKey('user-1'), value: 22 })
    store.rows.set('marks', new Map([['m1', {
      id: 'm1', version: 2, assessmentId: 'a1', studentId: 's1', criterionId: 'c1',
      markKind: 'score', score: 9, pendingBaseVersion: 2,
      pendingFields: { markKind: 'score', score: 9 }, sync: 'pending',
    }]]))
    const fetchMock = vi.fn().mockResolvedValue(
      json({
        changes: [{ table: 'marks', recordId: 'm1', version: 3, fields: { score: 6, lastEditedBy: 'teacher-2' } }],
        cursor: 23,
        more: false,
      }),
    )

    const result = await pullSync('token', 'user-1', fetchMock as unknown as typeof fetch, store.database)

    expect(result).toEqual({ pages: 1, changesApplied: 1, cursor: 23 })
    expect(store.rows.get('marks')?.get('m1')).toMatchObject({
      version: 3,
      score: 9,
      lastEditedBy: 'teacher-2',
      pendingBaseVersion: 2,
      pendingFields: { markKind: 'score', score: 9 },
      sync: 'pending',
    })
  })

  it('accepts numeric cursors across incremental log pages', async () => {
    const store = createDatabase()
    store.metadata.set(syncCursorKey('user-1'), { key: syncCursorKey('user-1'), value: 18 })
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
    store.metadata.set(syncCursorKey('user-1'), { key: syncCursorKey('user-1'), value: 18 })
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
    expect(store.metadata.has(syncCursorKey('user-1'))).toBe(false)
  })
})
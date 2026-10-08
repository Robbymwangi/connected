import { apiFetch, type FetchImpl } from '../api/client'
import {
  localDatabaseFor,
  SYNC_TABLES,
  type LocalDatabase,
  type LocalRecord,
  type SyncTableName,
} from './localDatabase'

const PAGE_LIMIT = 500

export type SyncChange = {
  table: SyncTableName
  recordId: string
  version: number
  fields: Record<string, unknown>
}

type SyncPage = {
  changes: SyncChange[]
  cursor: number | string
  more: boolean
}

export type SyncPullResult = {
  pages: number
  changesApplied: number
  cursor: number
}

export const syncCursorKey = (userId: string) => `syncCursor:${userId}`
const activePulls = new WeakMap<LocalDatabase, Promise<SyncPullResult>>()

function isSyncTable(value: unknown): value is SyncTableName {
  return typeof value === 'string' && SYNC_TABLES.some((table) => table === value)
}

function isFields(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function parsePage(value: unknown): SyncPage {
  if (typeof value !== 'object' || value === null) throw new Error('Invalid GET /sync response')

  const page = value as Record<string, unknown>
  if (
    !Array.isArray(page.changes) ||
    (typeof page.cursor !== 'string' && (!Number.isSafeInteger(page.cursor) || (page.cursor as number) < 0)) ||
    typeof page.more !== 'boolean' ||
    (!page.more && typeof page.cursor !== 'number')
  ) {
    throw new Error('Invalid GET /sync response')
  }

  const changes = page.changes.map((change): SyncChange => {
    if (typeof change !== 'object' || change === null) throw new Error('Invalid GET /sync change')
    const row = change as Record<string, unknown>
    if (
      !isSyncTable(row.table) ||
      typeof row.recordId !== 'string' ||
      !Number.isSafeInteger(row.version) ||
      (row.version as number) < 1 ||
      !isFields(row.fields)
    ) {
      throw new Error('Invalid GET /sync change')
    }

    return {
      table: row.table,
      recordId: row.recordId,
      version: row.version as number,
      fields: row.fields,
    }
  })

  return { changes, cursor: page.cursor as number | string, more: page.more }
}

async function applyPage(database: LocalDatabase, page: SyncPage, userId: string): Promise<number> {
  let applied = 0
  const tables = [...SYNC_TABLES.map((name) => database.table(name)), database.metadata]

  await database.transaction('rw', tables, async () => {
    for (const change of page.changes) {
      const table = database.table(change.table)
      const current = await table.get(change.recordId)
      if (current && current.version >= change.version) continue

      const record: LocalRecord = {
        ...(current ?? {}),
        ...change.fields,
        id: change.recordId,
        version: change.version,
      }
      await table.put(record)
      applied++
    }

    await database.metadata.put({ key: syncCursorKey(userId), value: page.cursor })
  })

  return applied
}

async function performPull(
  token: string,
  userId: string,
  fetchImpl: FetchImpl = fetch,
  store: LocalDatabase,
): Promise<SyncPullResult> {
  const storedCursor = await store.metadata.get(syncCursorKey(userId))
  let cursor = storedCursor?.value
  if (cursor !== undefined && typeof cursor !== 'number' && typeof cursor !== 'string') {
    throw new Error('Invalid stored sync cursor')
  }

  let pages = 0
  let changesApplied = 0
  let more = true

  while (more) {
    const isBootstrap = cursor === undefined || typeof cursor === 'string'
    const query = new URLSearchParams({ limit: String(PAGE_LIMIT) })
    if (cursor !== undefined) query.set('since', String(cursor))

    const response = parsePage(
      await apiFetch<unknown>(`/api/sync?${query.toString()}`, { token }, fetchImpl),
    )
    if (isBootstrap && response.more && typeof response.cursor !== 'string') {
      throw new Error('GET /sync bootstrap continuation cursor must be opaque')
    }
    if (!isBootstrap && response.more && typeof response.cursor !== 'number') {
      throw new Error('GET /sync incremental cursor must be numeric')
    }
    if (response.more && response.cursor === cursor) {
      throw new Error('GET /sync did not advance its continuation cursor')
    }

    changesApplied += await applyPage(store, response, userId)
    pages++
    cursor = response.cursor
    more = response.more
  }

  return { pages, changesApplied, cursor: cursor as number }
}

export function pullSync(
  token: string,
  userId: string,
  fetchImpl: FetchImpl = fetch,
  database?: LocalDatabase,
): Promise<SyncPullResult> {
  const store = database ?? localDatabaseFor(userId)
  const active = activePulls.get(store)
  if (active) return active

  const pull = performPull(token, userId, fetchImpl, store)
  activePulls.set(store, pull)
  const clearActivePull = () => {
    if (activePulls.get(store) === pull) activePulls.delete(store)
  }
  void pull.then(clearActivePull, clearActivePull)
  return pull
}
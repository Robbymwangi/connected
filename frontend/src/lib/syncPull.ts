import { apiFetch, type FetchImpl } from '../api/client'
import {
  localDatabaseFor,
  SYNC_TABLES,
  type LocalDatabase,
  type LocalRecord,
  type SyncTableName,
} from './localDatabase'
import { reconcileWithinTransaction } from './reconcileConflicts'
import { mapOf, withShadow } from './shadow'

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
  /* Mark edits held back by a conflict that this pull found resolved. Releasing one
     rebases the edit behind it, which creates no new queued entry, so the caller has to
     be told there is something to push. */
  released: number
}

export const syncCursorKey = (userId: string) => `syncCursor:${userId}`
const activePulls = new WeakMap<LocalDatabase, Promise<SyncPullResult>>()

function isSyncTable(value: unknown): value is SyncTableName {
  return typeof value === 'string' && SYNC_TABLES.some((table) => table === value)
}

function isFields(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/* One change as the server states it, whether in a pull page or as the `current` row
   of a push result; both are the same shape. */
export function parseSyncChange(change: unknown): SyncChange {
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

  const changes = page.changes.map(parseSyncChange)

  return { changes, cursor: page.cursor as number | string, more: page.more }
}

/* The fields each record has in flight, from the outbox. A queued, sent, or
   conflicted patch or finalize protects its fields from the pull; a command, an
   acknowledged entry, and a failed one protect nothing. */
async function protectedFields(database: LocalDatabase): Promise<Map<string, Set<string>>> {
  const open = await database.outbox.where('state').anyOf(['queued', 'sent', 'conflict']).toArray()
  const protectedBy = new Map<string, Set<string>>()
  for (const entry of open) {
    if (entry.kind === 'command') continue
    const key = `${entry.table}:${entry.recordId}`
    const fields = protectedBy.get(key) ?? new Set<string>()
    for (const field of Object.keys(entry.fields)) fields.add(field)
    protectedBy.set(key, fields)
  }
  return protectedBy
}

/* A protected field keeps the local value, since the outbox is about to send it; the
   server's value waits in the shadow, with the version it came from, for the entry's
   outcome (ADR 0011). A delete is never held back. The version never goes backwards.
   Shared by the pull and, with the row a conflict carries, the push. */
export function applyServerRow(
  current: LocalRecord | undefined,
  row: { recordId: string; version: number; fields: Record<string, unknown> },
  protectedHere?: ReadonlySet<string>,
): LocalRecord {
  const record: LocalRecord = { ...(current ?? {}), id: row.recordId, version: Math.max(current?.version ?? 0, row.version) }
  const shadow = mapOf(current?.serverShadow)
  const shadowAt = mapOf(current?.serverShadowAt)

  for (const [field, value] of Object.entries(row.fields)) {
    if (protectedHere?.has(field) && field !== 'deletedAt') {
      shadow[field] = value
      shadowAt[field] = row.version
    } else {
      record[field] = value
      delete shadow[field]
      delete shadowAt[field]
    }
  }
  if (Number.isSafeInteger(record.ackedVersion) && record.version > (record.ackedVersion as number)) delete record.ackedVersion

  return withShadow(record, shadow, shadowAt)
}

/* A settled push raises the record to the version the server answered, so the next
   edit bases on it. The log row at that version then arrives as an equal-version
   change, and carries what only the server writes (lastEditedBy, a finalize's own
   timestamp). It is applied once, to the fields no open entry protects, and the
   marker is cleared. */
function applyAckedRow(
  current: LocalRecord,
  row: { fields: Record<string, unknown> },
  protectedHere?: ReadonlySet<string>,
): LocalRecord {
  const record: LocalRecord = { ...current }
  const shadow = mapOf(current.serverShadow)
  const shadowAt = mapOf(current.serverShadowAt)

  for (const [field, value] of Object.entries(row.fields)) {
    if (protectedHere?.has(field) && field !== 'deletedAt') continue
    record[field] = value
    delete shadow[field]
    delete shadowAt[field]
  }
  delete record.ackedVersion

  return withShadow(record, shadow, shadowAt)
}

async function applyPage(database: LocalDatabase, page: SyncPage, userId: string): Promise<{ applied: number; released: number }> {
  let applied = 0
  let released = 0
  const tables = [...SYNC_TABLES.map((name) => database.table(name)), database.metadata, database.outbox]

  await database.transaction('rw', tables, async () => {
    const protectedBy = await protectedFields(database)

    for (const change of page.changes) {
      const table = database.table(change.table)
      const current = await table.get(change.recordId)
      const protectedHere = protectedBy.get(`${change.table}:${change.recordId}`)

      if (current && current.version >= change.version) {
        if (current.version !== change.version || current.ackedVersion !== change.version) continue
        await table.put(applyAckedRow(current, change, protectedHere))
        applied++
        continue
      }

      await table.put(applyServerRow(current, change, protectedHere))
      applied++
    }

    /* On the last page only: the server logs a conflict's resolution before the mark it
       writes, and a page can split the two, so only a finished pull has both. */
    if (!page.more) released = (await reconcileWithinTransaction(database)).released

    await database.metadata.put({ key: syncCursorKey(userId), value: page.cursor })
  })

  return { applied, released }
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
  let released = 0
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

    const page = await applyPage(store, response, userId)
    changesApplied += page.applied
    released += page.released
    pages++
    cursor = response.cursor
    more = response.more
  }

  return { pages, changesApplied, cursor: cursor as number, released }
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
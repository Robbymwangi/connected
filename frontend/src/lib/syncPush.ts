import { ApiError, apiFetch, type FetchImpl } from '../api/client'
import { localDatabaseFor, SYNC_TABLES, type LocalDatabase, type LocalRecord, type SyncTableName } from './localDatabase'
import { entriesForRecord, type OutboxEntry } from './outbox'
import { applyServerRow, mapOf, parseSyncChange, withShadow, type SyncChange } from './syncPull'

/* The push client (build-plan 3.4, docs/spec/sync-protocol.md "POST /sync"). One call
   sends one batch of the outbox and settles what the server answered. It does not
   decide when to run: the runner and the triggers are slice 4. Conflict commands are
   not sent yet; selection leaves them out until slice 5 gives them a settlement.

   Two rules shape everything here. An entry is frozen before it is sent, in a
   committed transaction, so a resend after a lost response is byte-identical and the
   server answers it `replayed`. And nothing awaits anything but Dexie inside a
   transaction; the request itself is made between two of them. */

export const BATCH_LIMIT = 100
const PUSH_TIMEOUT_MS = 30_000
const NOTICE_LIMIT = 200
const OPEN_STATES = ['queued', 'sent', 'conflict']

export const syncNoticesKey = 'syncNotices'

export type PushResultStatus = 'accepted' | 'merged' | 'conflict' | 'forbidden' | 'invalid'

export type PushResult = {
  id: string
  status: PushResultStatus
  version?: number
  conflictId?: string
  current?: SyncChange | null
  reason?: string
  replayed?: boolean
}

export type SyncPushResult = {
  sent: number
  settled: Record<PushResultStatus, number>
  /* Entries still queued afterwards: waiting for a base, or beyond this batch. */
  held: number
}

/* A conflict that has no record to resolve (an assessment, a notification, a deleted
   mark) is dropped from the outbox; this is what the teacher is told about it. */
export type SyncNotice = {
  id: string
  table: SyncTableName
  recordId: string
  kind: 'conflict'
  sent: Record<string, unknown>
  current: Record<string, unknown> | null
  at: string
}

/* The server answered 2xx with something this client cannot trust. Nothing is
   applied, every entry stays sent, and the next push resends them. */
export class SyncPushProtocolError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'SyncPushProtocolError'
  }
}

/* retry: the server was not reached or is unwell; try again later. reauth: the token
   is no longer good. defect: this will not get better by waiting (a token without the
   sync ability, a malformed batch, a reply the client cannot read). */
export type PushFailure = 'retry' | 'reauth' | 'defect'

export function classifyPushFailure(error: unknown): PushFailure {
  if (error instanceof ApiError) {
    if (error.status === 401) return 'reauth'
    if (error.status === 429 || error.status >= 500) return 'retry'
    return 'defect'
  }
  if (error instanceof TypeError || error instanceof DOMException) return 'retry'
  return 'defect'
}

const isOpen = (entry: OutboxEntry) => entry.kind !== 'command' && OPEN_STATES.includes(entry.state)

function emptyCounts(): Record<PushResultStatus, number> {
  return { accepted: 0, merged: 0, conflict: 0, forbidden: 0, invalid: 0 }
}

/* Picks the entries to send and freezes them. Sendable: already sent (a lost-response
   resend), or queued with a base. An entry with no base yet waits for its predecessor
   on the record. A finalize also waits while any earlier entry of its assessment is
   held back, or a mark held behind it would be refused as edit-blocked. */
async function freeze(database: LocalDatabase, limit: number): Promise<OutboxEntry[]> {
  return database.transaction('rw', [database.outbox, database.marks], async () => {
    const open = (await database.outbox.where('state').anyOf(OPEN_STATES).sortBy('seq')).filter((entry) => entry.kind !== 'command')
    const markRows = await database.marks.bulkGet(open.filter((entry) => entry.table === 'marks').map((entry) => entry.recordId))
    const assessmentOfMark = new Map(markRows.flatMap((row) => (row && typeof row.assessmentId === 'string' ? [[row.id, row.assessmentId] as const] : [])))
    const assessmentOf = (entry: OutboxEntry): string | null => {
      if (entry.table === 'assessments') return entry.recordId
      if (entry.table !== 'marks') return null
      const fromFields = entry.fields.assessmentId
      return assessmentOfMark.get(entry.recordId) ?? (typeof fromFields === 'string' ? fromFields : null)
    }

    const held = new Set<string>()
    const selected: OutboxEntry[] = []
    for (const entry of open) {
      const assessmentId = assessmentOf(entry)
      const ready =
        (entry.state === 'sent' || (entry.state === 'queued' && entry.baseVersion !== null)) &&
        !(entry.kind === 'finalize' && assessmentId !== null && held.has(assessmentId)) &&
        selected.length < limit
      if (ready) selected.push(entry)
      else if (assessmentId !== null) held.add(assessmentId)
    }

    await database.outbox.bulkUpdate(
      selected.filter((entry) => entry.state === 'queued').map((entry) => ({ key: entry.seq as number, changes: { state: 'sent' } })),
    )
    return selected.map((entry) => ({ ...entry, state: 'sent' as const }))
  })
}

function protocolError(message: string): never {
  throw new SyncPushProtocolError(`Invalid POST /sync response: ${message}`)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const STATUSES: readonly string[] = ['accepted', 'merged', 'conflict', 'forbidden', 'invalid']

function parseResults(raw: unknown, sent: OutboxEntry[]): PushResult[] {
  if (!isRecord(raw) || !Array.isArray(raw.results)) protocolError('no results list')
  const results = raw.results as unknown[]
  if (results.length !== sent.length) protocolError(`${results.length} results for ${sent.length} entries`)

  return results.map((value, index): PushResult => {
    const entry = sent[index]
    if (!isRecord(value)) protocolError('a result is not an object')
    if (value.id !== entry.id) protocolError('a result does not match its entry')
    if (typeof value.status !== 'string' || !STATUSES.includes(value.status)) protocolError('unknown status')
    if (value.replayed !== undefined && value.replayed !== true) protocolError('replayed must be true when present')
    const status = value.status as PushResultStatus
    const result: PushResult = { id: entry.id, status, ...(value.replayed === true ? { replayed: true } : {}) }

    if (status === 'accepted' || status === 'merged') {
      const version = value.version
      if (!Number.isSafeInteger(version) || (version as number) < 1 || (version as number) < (entry.baseVersion ?? 0)) {
        protocolError('bad version')
      }
      result.version = version as number
    }

    if (status === 'conflict') {
      if (value.current === null && value.replayed === true) {
        result.current = null
      } else {
        let current: SyncChange
        try {
          current = parseSyncChange(value.current)
        } catch {
          protocolError('conflict without a valid current row')
        }
        if (current.table !== entry.table || current.recordId !== entry.recordId) protocolError('current row is for another record')
        result.current = current
      }
      if (value.conflictId !== undefined) {
        if (typeof value.conflictId !== 'string' || value.conflictId === '' || entry.table !== 'marks') protocolError('bad conflictId')
        result.conflictId = value.conflictId
      }
    }

    if (status === 'invalid') {
      if (typeof value.reason !== 'string') protocolError('invalid without a reason')
      result.reason = value.reason
    }

    return result
  })
}

function flagged(record: LocalRecord, open: boolean): LocalRecord {
  const next: LocalRecord = { ...record }
  if (open) {
    next.sync = 'pending'
  } else {
    delete next.sync
    delete next.localAuthor
  }
  return next
}

/* The record after its entry was accepted or merged at version V. */
function settledRecord(record: LocalRecord, entry: OutboxEntry, version: number, coveredLater: ReadonlySet<string>): LocalRecord {
  const next: LocalRecord = { ...record, version: Math.max(record.version, version) }
  if (record.version < version) next.ackedVersion = version

  const shadow = mapOf(record.serverShadow)
  const shadowAt = mapOf(record.serverShadowAt)
  for (const field of Object.keys(entry.fields)) {
    const shadowVersion = Number(shadowAt[field])
    if (coveredLater.has(field)) {
      /* A later entry still holds the field, so the server's value now is the one
         just acknowledged, unless a pull has already landed a newer one. */
      if (!(shadowVersion > version)) {
        shadow[field] = entry.fields[field]
        shadowAt[field] = version
      }
    } else {
      /* The server holds the local value, unless a pull landed a newer one while
         the entry was in flight, in which case the newer one wins. */
      if (shadowVersion > version) next[field] = shadow[field]
      delete shadow[field]
      delete shadowAt[field]
    }
  }
  return withShadow(next, shadow, shadowAt)
}

function revertedRecord(record: LocalRecord, entry: OutboxEntry, coveredLater: ReadonlySet<string>): LocalRecord {
  const next: LocalRecord = { ...record }
  const shadow = mapOf(record.serverShadow)
  const shadowAt = mapOf(record.serverShadowAt)
  for (const field of Object.keys(entry.fields)) {
    if (coveredLater.has(field) || !(field in shadow)) continue
    next[field] = shadow[field]
    delete shadow[field]
    delete shadowAt[field]
  }
  return withShadow(next, shadow, shadowAt)
}

async function failQueued(database: LocalDatabase, entries: OutboxEntry[], reason: string): Promise<void> {
  const queued = entries.filter((entry) => entry.state === 'queued')
  await database.outbox.bulkUpdate(queued.map((entry) => ({ key: entry.seq as number, changes: { state: 'failed', reason } })))
}

/* A create the server refused leaves nothing to keep locally: the row was never real,
   and what waited on it can only be refused too. The failed entry keeps every value
   that was sent, so the teacher can be offered the create again (3.5). */
async function discardRefusedCreate(database: LocalDatabase, entry: OutboxEntry, later: OutboxEntry[]): Promise<void> {
  await failQueued(database, later, 'create was refused')
  await database.table(entry.table).delete(entry.recordId)
  if (entry.table !== 'assessments') return

  for (const child of await database.marks.where('assessmentId').equals(entry.recordId).toArray()) {
    const childEntries = (await entriesForRecord(database, 'marks', child.id)).filter(isOpen)
    await failQueued(database, childEntries, 'parent create was refused')
    const stillOpen = (await entriesForRecord(database, 'marks', child.id)).some(isOpen)
    if (child.version === 0 && !stillOpen) await database.marks.delete(child.id)
  }
}

async function settle(database: LocalDatabase, entry: OutboxEntry, result: PushResult, notices: SyncNotice[]): Promise<boolean> {
  /* Another run may have settled or cleared this entry while the request was out. */
  const fresh = await database.outbox.get(entry.seq as number)
  if (!fresh || fresh.id !== entry.id || fresh.state !== 'sent') return false

  const table = database.table(entry.table)
  let record = await table.get(entry.recordId)
  const siblings = (await entriesForRecord(database, entry.table, entry.recordId)).filter(isOpen)
  const later = siblings.filter((other) => (other.seq as number) > (entry.seq as number))
  const coveredLater = new Set(later.flatMap((other) => Object.keys(other.fields)))
  const next = later.find((other) => other.state === 'queued' && other.baseVersion === null)
  const rebase = (baseVersion: number | null) => next && baseVersion !== null
    ? database.outbox.update(next.seq as number, { baseVersion })
    : undefined
  let discarded = false

  if (result.status === 'accepted' || result.status === 'merged') {
    const version = result.version as number
    await database.outbox.delete(entry.seq as number)
    await rebase(version)
    if (record) record = settledRecord(record, entry, version, coveredLater)
  } else if (result.status === 'conflict' && result.conflictId) {
    await database.outbox.update(entry.seq as number, { state: 'conflict', conflictId: result.conflictId })
    const protectedAll = new Set(siblings.flatMap((other) => Object.keys(other.fields)))
    if (record && result.current) record = applyServerRow(record, result.current, protectedAll)
  } else if (result.status === 'conflict') {
    await database.outbox.delete(entry.seq as number)
    await rebase(entry.baseVersion)
    if (result.current) record = applyServerRow(record, result.current, coveredLater)
    notices.push({
      id: entry.id, table: entry.table, recordId: entry.recordId, kind: 'conflict',
      sent: entry.fields, current: result.current?.fields ?? null, at: entry.at,
    })
  } else {
    const reason = result.status === 'forbidden' ? 'forbidden' : (result.reason ?? 'invalid')
    await database.outbox.update(entry.seq as number, { state: 'failed', reason })
    if (record && record.version === 0 && entry.baseVersion === 0 && entry.kind === 'patch') {
      await discardRefusedCreate(database, entry, later)
      discarded = true
    } else {
      await rebase(entry.baseVersion)
      if (record) record = revertedRecord(record, entry, coveredLater)
    }
  }

  if (record && !discarded) {
    const open = (await entriesForRecord(database, entry.table, entry.recordId)).some(isOpen)
    await table.put(flagged(record, open))
  }
  return true
}

async function applyResults(database: LocalDatabase, sent: OutboxEntry[], results: PushResult[]): Promise<Record<PushResultStatus, number>> {
  const counts = emptyCounts()
  const tables = [...SYNC_TABLES.map((name) => database.table(name)), database.outbox, database.metadata]

  await database.transaction('rw', tables, async () => {
    const notices: SyncNotice[] = []
    for (const [index, result] of results.entries()) {
      if (await settle(database, sent[index], result, notices)) counts[result.status]++
    }

    if (notices.length) {
      const stored = (await database.metadata.get(syncNoticesKey))?.value
      const known = Array.isArray(stored) ? stored as SyncNotice[] : []
      const fresh = notices.filter((notice) => !known.some((item) => item.id === notice.id))
      await database.metadata.put({ key: syncNoticesKey, value: [...known, ...fresh].slice(-NOTICE_LIMIT) })
    }
  })
  return counts
}

async function performPush(token: string, fetchImpl: FetchImpl, database: LocalDatabase, limit: number): Promise<SyncPushResult> {
  const sent = await freeze(database, limit)
  const held = () => database.outbox.where('state').equals('queued').count()
  if (sent.length === 0) return { sent: 0, settled: emptyCounts(), held: await held() }

  const body = {
    entries: sent.map((entry) => ({
      id: entry.id, table: entry.table, recordId: entry.recordId,
      baseVersion: entry.baseVersion, fields: entry.fields, at: entry.at,
    })),
  }
  const raw = await apiFetch<unknown>('/api/sync', { method: 'POST', token, body, timeoutMs: PUSH_TIMEOUT_MS }, fetchImpl)
  const settled = await applyResults(database, sent, parseResults(raw, sent))

  return { sent: sent.length, settled, held: await held() }
}

const activePushes = new WeakMap<LocalDatabase, Promise<SyncPushResult>>()

/* One batch for the signed-in account. Single-flight per database, like pullSync, and
   the database is the token user's own, so one account's pending entries are never
   pushed as another's. Failures are thrown, never swallowed; classifyPushFailure says
   what the caller should do about them. */
export function pushSync(
  token: string,
  userId: string,
  fetchImpl: FetchImpl = fetch,
  database?: LocalDatabase,
  batchLimit = BATCH_LIMIT,
): Promise<SyncPushResult> {
  const store = database ?? localDatabaseFor(userId)
  const active = activePushes.get(store)
  if (active) return active

  const push = performPush(token, fetchImpl, store, batchLimit)
  activePushes.set(store, push)
  const clear = () => {
    if (activePushes.get(store) === push) activePushes.delete(store)
  }
  void push.then(clear, clear)
  return push
}

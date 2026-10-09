import type { LocalDatabase, SyncTableName } from './localDatabase'

/* The outbox holds the protocol envelope of every local write that the server has
   not yet settled (docs/spec/sync-protocol.md, "The mutation"). The record keeps the
   value the UI shows; the entry keeps what must be sent, which stops changing once
   it has been sent. */

export type OutboxKind = 'patch' | 'finalize' | 'command'

/* queued: not yet sent, free to coalesce. sent: frozen, resent byte-identically
   until answered. conflict: the server raised a conflict and the entry is retained.
   acked: a command the server accepted, kept to drive the display overlay until
   the pull catches up. failed: refused as invalid or forbidden, never resent. */
export type OutboxState = 'queued' | 'sent' | 'conflict' | 'acked' | 'failed'

export type OutboxEntry = {
  seq?: number
  id: string
  table: SyncTableName
  recordId: string
  kind: OutboxKind
  /* null means the entry waits for an earlier entry on the same record to be
     answered; its base is then the version the server returns. */
  baseVersion: number | null
  fields: Record<string, unknown>
  at: string
  state: OutboxState
  conflictId?: string
  reason?: string
  ackVersion?: number
}

export type EnqueueInput = {
  table: SyncTableName
  recordId: string
  kind: OutboxKind
  /* The version of the record when the user made the edit; for a command, the
     conflict's own version. */
  baseVersion: number
  fields: Record<string, unknown>
  at: string
}

const FINALIZE_FIELDS = ['status', 'finalizedBy', 'finalizedAt']
const COMMAND_KEYS = ['proposal', 'referral', 'resolution']

/* The tables a device may push, and for notifications the one field it may
   (docs/spec/sync-protocol.md, Scope). Every other table is pull-only. */
const PATCH_TABLES: readonly SyncTableName[] = ['marks', 'assessments', 'notifications']
const NOTIFICATION_FIELDS = ['unread']

/* Entries that still stand between the device and the server's answer for a
   record. A later edit must not assume a base while one of them is open. */
const OPEN_STATES: readonly OutboxState[] = ['queued', 'sent', 'conflict']

export async function entriesForRecord(
  database: LocalDatabase,
  table: SyncTableName,
  recordId: string,
): Promise<OutboxEntry[]> {
  return database.outbox.where('[table+recordId]').equals([table, recordId]).sortBy('seq')
}

function validate(input: EnqueueInput): void {
  if (!Number.isSafeInteger(input.baseVersion) || input.baseVersion < 0) {
    throw new Error('An outbox entry needs a non-negative integer base version')
  }
  const keys = Object.keys(input.fields)

  if (input.kind === 'command') {
    if (input.table !== 'conflicts') throw new Error('Commands belong to the conflicts table')
    if (keys.length !== 1 || !COMMAND_KEYS.includes(keys[0])) {
      throw new Error('A conflict command carries exactly one of proposal, referral, or resolution')
    }
    return
  }
  if (input.table === 'conflicts') throw new Error('A conflict is changed by commands, never by field patches')

  if (input.kind === 'finalize') {
    if (input.table !== 'assessments') throw new Error('Only an assessment can be finalized')
    if (keys.length !== FINALIZE_FIELDS.length || !keys.every((key) => FINALIZE_FIELDS.includes(key))) {
      throw new Error('A finalize entry carries status, finalizedBy, and finalizedAt only')
    }
    return
  }
  if (!PATCH_TABLES.includes(input.table)) throw new Error(`A device cannot push changes to ${input.table}`)
  if (input.table === 'notifications' && !keys.every((key) => NOTIFICATION_FIELDS.includes(key))) {
    throw new Error('A device may change only unread on a notification')
  }
  if (keys.some((key) => FINALIZE_FIELDS.includes(key))) {
    throw new Error('Finalize fields are sent as a finalize entry, never in a patch')
  }
  if (keys.length === 0) throw new Error('A patch must change at least one field')
}

/* Appends an entry, or folds a patch into the record's unsent trailing patch
   (ADR 0001 rule 4). Call it inside the transaction that writes the record, so the
   two can never disagree; standing alone it opens its own. */
export async function enqueue(database: LocalDatabase, input: EnqueueInput): Promise<OutboxEntry> {
  validate(input)

  return database.transaction('rw', database.outbox, async () => {
    const existing = await entriesForRecord(database, input.table, input.recordId)
    const last = existing[existing.length - 1]

    /* The server closes an assessment to edits once it is finalized, so a patch
       queued behind an open finalize could only be refused. */
    if (input.kind === 'patch' && existing.some((entry) => entry.kind === 'finalize' && OPEN_STATES.includes(entry.state))) {
      throw new Error('This record has a finalize waiting to be sent; it takes no further edits')
    }

    if (input.kind === 'patch' && last && last.kind === 'patch' && last.state === 'queued') {
      const merged: OutboxEntry = {
        ...last,
        fields: { ...last.fields, ...input.fields },
        at: input.at,
      }
      await database.outbox.update(last.seq as number, { fields: merged.fields, at: merged.at })
      return merged
    }

    const waits = existing.some((entry) => OPEN_STATES.includes(entry.state))
    const entry: OutboxEntry = {
      id: crypto.randomUUID(),
      table: input.table,
      recordId: input.recordId,
      kind: input.kind,
      baseVersion: waits ? null : input.baseVersion,
      fields: input.fields,
      at: input.at,
      state: 'queued',
    }
    entry.seq = await database.outbox.add(entry)
    return entry
  })
}

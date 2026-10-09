import type { LocalRecord } from './localDatabase'
import type { OutboxEntry } from './outbox'

/* Helpers for a record's pending flag and its server shadow (ADR 0011), shared by the
   pull, the push, and the conflict reconciliation. They live apart from all three so
   none has to import another. */

export function mapOf(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? { ...value as Record<string, unknown> } : {}
}

export function withShadow(record: LocalRecord, shadow: Record<string, unknown>, shadowAt: Record<string, unknown>): LocalRecord {
  if (Object.keys(shadow).length) {
    record.serverShadow = shadow
    record.serverShadowAt = shadowAt
  } else {
    delete record.serverShadow
    delete record.serverShadowAt
  }
  return record
}

export function flagged(record: LocalRecord, open: boolean): LocalRecord {
  const next: LocalRecord = { ...record }
  if (open) {
    next.sync = 'pending'
  } else {
    delete next.sync
    delete next.localAuthor
  }
  return next
}

export function revertedRecord(record: LocalRecord, entry: OutboxEntry, coveredLater: ReadonlySet<string>): LocalRecord {
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


import { expect } from 'vitest'
import type { LocalDatabase } from './localDatabase'

/* The invariant that keeps the record's pending flag from becoming a second source of
   truth (ADR 0011, decision 2): a record is `sync: 'pending'` exactly when the outbox
   holds an open patch or finalize entry for it. Call after every writer, migration,
   and pull in a test. */
export async function assertDisplayFlags(database: LocalDatabase): Promise<void> {
  const open = new Set(
    (await database.outbox.toArray())
      .filter((entry) => entry.kind !== 'command' && ['queued', 'sent', 'conflict'].includes(entry.state))
      .map((entry) => `${entry.table}:${entry.recordId}`),
  )

  for (const table of ['assessments', 'marks'] as const) {
    for (const record of await database.table(table).toArray()) {
      expect(record.sync === 'pending', `${table}:${record.id} pending flag`).toBe(open.has(`${table}:${record.id}`))
    }
  }
}

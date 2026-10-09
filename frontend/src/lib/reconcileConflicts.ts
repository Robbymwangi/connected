import type { LocalDatabase, LocalRecord } from './localDatabase'
import { entriesForRecord, type OutboxEntry } from './outbox'
import { flagged, revertedRecord } from './shadow'

/* Settles the outbox against the conflicts a pull has brought in. It runs when a pull
   completes, in the same transaction as the pull's cursor, because the server logs a
   conflict's resolution before it logs the mark it writes, and a page can split the two.
   Only a finished pull has both.

   Two jobs. An acknowledged command has done its work once the conflict row it was
   answered at has been pulled, or the conflict has resolved: until then it drives the
   display overlay, afterwards it is clutter. And a mark edit that was retained because
   it conflicted has nothing left to wait for once the conflict is resolved, by anyone:
   the settled mark is already in the shadow, the cell takes it, and an edit that was
   held behind the conflict is released. */

const OPEN_PATCH_STATES = ['queued', 'sent', 'conflict']

/* The base a held edit is sent at once its conflict has resolved. The resolution wrote
   a new version of the mark, so by default the edit is judged against that: rule 2, it
   is accepted, and the later edit wins. Returning `entry.baseVersion` instead (the
   version the teacher saw before the conflict) would make the server judge it as a
   stale write, and it would surface as a new conflict. A spec decision, in one place. */
export function baseAfterResolution(mark: LocalRecord, _retained: OutboxEntry): number {
  return mark.version
}

const isOpenPatch = (entry: OutboxEntry) => entry.kind !== 'command' && OPEN_PATCH_STATES.includes(entry.state)

/* The work itself, for a caller that already holds a read-write transaction over the
   conflicts, marks, and outbox tables (the pull, which commits this with its cursor). */
export async function reconcileWithinTransaction(database: LocalDatabase): Promise<{ released: number }> {
  const acked = await database.outbox.where('state').equals('acked')
    .filter((entry) => entry.kind === 'command' && entry.table === 'conflicts').toArray()
  for (const command of acked) {
    const row = await database.conflicts.get(command.recordId)
    const caughtUp = row !== undefined && command.ackVersion !== undefined && row.version >= command.ackVersion
    if (!row || row.resolvedAt != null || caughtUp || command.ackVersion === undefined) {
      await database.outbox.delete(command.seq as number)
    }
  }

  let released = 0
  const retained = await database.outbox.where('state').equals('conflict')
    .filter((entry) => entry.table === 'marks' && entry.kind === 'patch' && typeof entry.conflictId === 'string')
    .sortBy('seq')
  for (const entry of retained) {
    const conflict = await database.conflicts.get(entry.conflictId as string)
    if (!conflict || conflict.resolvedAt == null) continue

    await database.outbox.delete(entry.seq as number)
    const mark = await database.marks.get(entry.recordId)
    if (mark) {
      const later = (await entriesForRecord(database, 'marks', entry.recordId))
        .filter((other) => isOpenPatch(other) && (other.seq as number) > (entry.seq as number))
      const coveredLater = new Set(later.flatMap((other) => Object.keys(other.fields)))
      const settled = revertedRecord(mark, entry, coveredLater)

      const next = later.find((other) => other.state === 'queued' && other.baseVersion === null)
      if (next) await database.outbox.update(next.seq as number, { baseVersion: baseAfterResolution(settled, entry) })

      const stillOpen = (await entriesForRecord(database, 'marks', entry.recordId)).some(isOpenPatch)
      await database.marks.put(flagged(settled, stillOpen))
    }
    released++
  }
  return { released }
}

export function reconcileConflicts(database: LocalDatabase): Promise<{ released: number }> {
  return database.transaction('rw', [database.conflicts, database.marks, database.outbox], () => reconcileWithinTransaction(database))
}

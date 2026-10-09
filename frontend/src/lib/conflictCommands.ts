import type { ActiveConflict, Choice } from '../fixtures/conflicts'
import { planCommand, type CommandRequest, type Resolver } from './conflicts'
import type { LocalDatabase, LocalRecord } from './localDatabase'
import { enqueue, entriesForRecord, type OutboxEntry } from './outbox'

/* The four things a teacher can do to a conflict (docs/spec/sync-protocol.md,
   "Conflict commands"), as outbox entries. A command is not a field patch: it names
   what it acts on, and the server decides it against the conflict's own version. So it
   is sent against the version of the conflict the teacher was shown, and it never writes
   a mark patch (ADR 0011, decision 6): the server writes the chosen mark itself, and a
   second, local write of the same value would reach it after the first and raise a
   spurious conflict. */

/* The conflict is no longer what the teacher was looking at: it was resolved, deleted, or
   is not on this device, or the version they were shown never existed here. Nothing was
   queued; the screen redraws from the database on its own. */
export class ConflictMovedError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ConflictMovedError'
  }
}

export type ConflictCommand =
  | { kind: 'propose'; byId: string; choice: Choice; note: string }
  | { kind: 'refer'; byId: string }
  | { kind: 'accept'; proposedById: string; acceptedById: string }
  | { kind: 'resolve'; resolution: 'self' | 'moderated'; byId: string; choice: Choice; note?: string }

/* A choice as the wire carries it: ids and values only, and never an empty mark. */
function wireChoice(choice: Choice): Record<string, unknown> {
  if (choice.kind === 'side') return { kind: 'side', editId: choice.editId }
  const { mark } = choice
  if (mark.kind === 'score') return { kind: 'corrected', mark: { kind: 'score', value: mark.value } }
  if (mark.kind === 'absent') return { kind: 'corrected', mark: { kind: 'absent' } }
  throw new RangeError('A corrected mark must be a score or absent, never empty')
}

/* The `fields` of a command entry, built here and nowhere else. Display names and the
   claimed time are not part of it: the server fills the names and the entry's own `at`
   is the time. */
export function commandFields(command: ConflictCommand): Record<string, unknown> {
  switch (command.kind) {
    case 'propose':
      return { proposal: { byId: command.byId, choice: wireChoice(command.choice), note: command.note } }
    case 'refer':
      return { referral: { byId: command.byId } }
    case 'accept':
      return { resolution: { kind: 'agreed', proposedById: command.proposedById, acceptedById: command.acceptedById } }
    case 'resolve': {
      const resolution = { kind: command.resolution, byId: command.byId, choice: wireChoice(command.choice) }
      if (command.resolution === 'self') return { resolution }
      if (!command.note || command.note.trim() === '') throw new RangeError('A moderated resolution needs a note')
      return { resolution: { ...resolution, note: command.note } }
    }
  }
}

/* The version of the conflict this device can vouch for: the row's, or higher when the
   server has answered a command and the pull has not yet delivered the new row. Without
   it, a second command written between the answer and the pull would carry the old
   version and come back stale. */
export function effectiveVersion(row: LocalRecord, entries: readonly OutboxEntry[]): number {
  const answered = entries
    .filter((entry) => entry.kind === 'command' && entry.state === 'acked' && entry.ackVersion !== undefined)
    .map((entry) => entry.ackVersion as number)
  return Math.max(row.version, ...answered)
}

/* Queues a command against the version of the conflict the teacher was shown. Refused
   for a conflict that is not on this device, was deleted, or is already resolved, and
   for a version this device could not have seen. A command that is merely out of date is
   sent as it is: the server answering that it is stale is how the teacher learns the
   conflict moved. enqueue gives it no base while an earlier command on the conflict is
   still open, to take the version that command is answered at. */
export function enqueueConflictCommand(
  database: LocalDatabase,
  conflictId: string,
  shownVersion: number,
  command: ConflictCommand,
  at: string,
): Promise<OutboxEntry> {
  return database.transaction('rw', [database.conflicts, database.outbox], async () => {
    const row = await database.conflicts.get(conflictId)
    if (!row || row.deletedAt != null || row.resolvedAt != null || row.resolution != null) {
      throw new ConflictMovedError('This conflict is no longer open on this device')
    }
    const entries = await entriesForRecord(database, 'conflicts', conflictId)
    if (shownVersion > effectiveVersion(row, entries)) {
      throw new ConflictMovedError('This device has not seen that version of the conflict')
    }

    return enqueue(database, {
      table: 'conflicts', recordId: conflictId, kind: 'command', baseVersion: shownVersion, fields: commandFields(command), at,
    })
  })
}

/* A teacher's request to act on the conflict in front of them: asks the policy whether
   they may, and if so queues the command. Whether anything was queued is the answer, so
   a screen can say "resolved" only when it was. Nothing is queued, and nothing is
   thrown, when the conflict is no longer in view, the policy refuses, or it moved on
   while the teacher was deciding (the screen redraws from the database on its own). */
export async function requestConflictCommand(
  database: LocalDatabase,
  conflict: ActiveConflict | undefined,
  request: CommandRequest,
  user: Resolver,
  max: number,
  at: string,
): Promise<boolean> {
  if (!conflict) return false
  const command = planCommand(conflict, request, user, max)
  if (!command) return false
  try {
    await enqueueConflictCommand(database, conflict.id, conflict.conflictVersion, command, at)
    return true
  } catch (error) {
    if (error instanceof ConflictMovedError) return false
    throw error
  }
}

/* What to do with the result of requestConflictCommand: announce a command that was
   queued, say so when the write failed, and say nothing when the conflict had moved on.
   Without it a screen either drops a failed write silently or leaves the rejection
   unhandled. */
export function reportRequest(request: Promise<boolean>, report: { queued?: () => void; failed: () => void }): void {
  void request.then((queued) => { if (queued) report.queued?.() }, () => report.failed())
}

/* A card that fades out as it settles must come back if the settling did not happen:
   the conflict is still in the list, and a faded card is invisible and cannot be used.
   Queued means the conflict will leave the list on its own. */
export function restoreUnlessQueued(request: Promise<boolean>, restore: () => void): void {
  void request.then((queued) => { if (!queued) restore() }, () => restore())
}

type Actor = { id: string; name: string }
type Applied = { row: LocalRecord; local: boolean; version: number }

const isObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

/* What the conflict looks like with this device's commands applied on top of the row it
   last pulled: a proposal appended, a referral set, the conflict settled. Derived from
   stored entries, so it survives a reload and the gap between the server's answer and
   the pull. It stops applying when the server's own row shows the same thing: a row
   that is resolved is returned as it is, whoever resolved it; an answered command applies
   only until the row reaches the version it was answered at. */
export function overlayConflictRow(row: LocalRecord, commands: readonly OutboxEntry[], actor: Actor): Applied {
  const version = effectiveVersion(row, commands)
  if (row.resolvedAt != null || row.resolution != null) return { row, local: false, version: row.version }

  const applying = commands
    .filter((entry) => entry.kind === 'command' && entry.table === 'conflicts' && entry.recordId === row.id)
    .filter((entry) => entry.state === 'queued' || entry.state === 'sent' || (entry.state === 'acked' && (entry.ackVersion ?? 0) > row.version))
    .sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0))
  if (applying.length === 0) return { row, local: false, version: row.version }

  const next: LocalRecord = { ...row }
  let proposals = Array.isArray(row.proposals) ? [...row.proposals as Record<string, unknown>[]] : []
  let shown = 0

  for (const entry of applying) {
    const { proposal, referral, resolution } = entry.fields as Record<string, unknown>

    if (isObject(proposal)) {
      proposals = [...proposals, { byId: proposal.byId, by: actor.name, choice: proposal.choice, note: proposal.note, at: entry.at }]
      shown++
    } else if (isObject(referral)) {
      next.referral = proposals.length >= 2
        ? { reason: 'rounds', at: entry.at }
        : { reason: 'party', byId: actor.id, by: actor.name, at: entry.at }
      shown++
    } else if (isObject(resolution)) {
      if (resolution.kind === 'agreed') {
        /* An acceptance copies the proposal it accepts. With none to copy there is
           nothing true to show, and a half-built agreement would be unreadable. */
        const pending = proposals[proposals.length - 1]
        if (!pending) continue
        next.resolution = {
          kind: 'agreed', proposedById: pending.byId, proposedBy: pending.by, acceptedById: actor.id, acceptedBy: actor.name,
          choice: pending.choice, note: pending.note,
        }
      } else {
        next.resolution = { ...resolution, by: actor.name }
      }
      next.resolvedAt = entry.at
      shown++
    }
  }
  if (shown === 0) return { row, local: false, version }
  next.proposals = proposals
  return { row: next, local: true, version }
}

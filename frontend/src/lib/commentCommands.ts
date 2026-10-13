import { effectiveVersion } from './conflictCommands'
import type { LocalDatabase, LocalRecord } from './localDatabase'
import { enqueue, entriesForRecord, type OutboxEntry } from './outbox'

/* The three things a teacher can do to a report-card comment (docs/spec/sync-protocol.md,
   "Comment commands"), as outbox entries. Like a conflict command, a comment command names
   what to do rather than sending field values: the server decides it against the comment's
   own version, and answers a stale one with the comment as it stands instead of merging it,
   so an acceptance can never go through over a body the acceptor did not see. Nothing is
   written to the comment row here; overlayCommentRow shows the command until the pull
   delivers the server's row. */

export type CommentState = 'pending' | 'draft' | 'accepted' | 'failed'

export type CommentCommand =
  | { kind: 'edit'; byId: string; body: string }
  | { kind: 'accept'; byId: string }
  | { kind: 'regenerate'; byId: string }

/* What fits on the card; the server holds the same limit. */
export const COMMENT_BODY_LIMIT = 600

/* The states each command acts on, as the server checks them. Nothing acts on an accepted
   comment, which is final until an unlock. */
const FROM: Record<CommentCommand['kind'], readonly CommentState[]> = {
  edit: ['pending', 'draft', 'failed'],
  accept: ['draft'],
  regenerate: ['pending', 'draft', 'failed'],
}

/* The comment is no longer what the teacher was looking at, or the command does not fit
   it: nothing was queued, and the screen redraws from the database on its own. */
export class CommentMovedError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'CommentMovedError'
  }
}

/* A body as the server will store it, or why it cannot be one. */
export function commentBodyProblem(body: string): string | null {
  const trimmed = body.trim()
  if (trimmed === '') return 'Write the comment before saving it'
  if ([...trimmed].length > COMMENT_BODY_LIMIT) return `A comment is at most ${COMMENT_BODY_LIMIT} characters`
  if (trimmed.includes('\u0000')) return 'The comment contains a character that cannot be saved'
  return null
}

export function canApply(state: CommentState, kind: CommentCommand['kind']): boolean {
  return FROM[kind].includes(state)
}

/* The `fields` of a comment command, built here and nowhere else. */
export function commentCommandFields(command: CommentCommand): Record<string, unknown> {
  switch (command.kind) {
    case 'edit': {
      const problem = commentBodyProblem(command.body)
      if (problem) throw new RangeError(problem)
      return { edit: { byId: command.byId, body: command.body.trim() } }
    }
    case 'accept':
      return { accept: { byId: command.byId } }
    case 'regenerate':
      return { regenerate: { byId: command.byId } }
  }
}

const isState = (value: unknown): value is CommentState =>
  value === 'pending' || value === 'draft' || value === 'accepted' || value === 'failed'

type Applied = { row: LocalRecord; local: boolean; version: number }

/* What the comment looks like with this device's commands applied on top of the row it last
   pulled: an edit makes a draft with the new body and the editor as author, an accept makes
   it accepted with the acceptor as author, a regenerate empties it back to pending. Derived
   from stored entries, so it survives a reload and the gap between the server's answer and
   the pull. A command answered at version V stops applying once the row reaches V. */
export function overlayCommentRow(row: LocalRecord, commands: readonly OutboxEntry[]): Applied {
  const version = effectiveVersion(row, commands)
  const applying = commands
    .filter((entry) => entry.kind === 'command' && entry.table === 'comments' && entry.recordId === row.id)
    .filter((entry) => entry.state === 'queued' || entry.state === 'sent' || (entry.state === 'acked' && (entry.ackVersion ?? 0) > row.version))
    .sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0))
  if (applying.length === 0) return { row, local: false, version: row.version }

  const next: LocalRecord = { ...row }
  for (const entry of applying) {
    const { edit, accept, regenerate } = entry.fields as Record<string, { byId?: unknown; body?: unknown } | undefined>
    if (edit) Object.assign(next, { body: edit.body, state: 'draft', authorId: edit.byId })
    else if (accept) Object.assign(next, { state: 'accepted', authorId: accept.byId })
    else if (regenerate) Object.assign(next, { body: null, state: 'pending', authorId: null })
  }
  return { row: next, local: true, version }
}

/* Queues a command against the version of the comment the teacher was shown. Refused for a
   comment that is not on this device or was withdrawn by an unlock, for a version this
   device could not have seen, and for a command the comment's state (with this device's
   earlier commands applied) does not take. A command that is merely out of date is sent as
   it is: the server answering that it is stale is how the teacher learns a colleague got
   there first. */
export function enqueueCommentCommand(
  database: LocalDatabase,
  commentId: string,
  shownVersion: number,
  command: CommentCommand,
  at: string,
): Promise<OutboxEntry> {
  const fields = commentCommandFields(command)

  return database.transaction('rw', [database.comments, database.outbox], async () => {
    const row = await database.comments.get(commentId)
    if (!row || row.deletedAt != null) throw new CommentMovedError('This comment is no longer on this device')
    const entries = await entriesForRecord(database, 'comments', commentId)
    if (shownVersion > effectiveVersion(row, entries)) {
      throw new CommentMovedError('This device has not seen that version of the comment')
    }
    const state = overlayCommentRow(row, entries).row.state
    if (!isState(state) || !canApply(state, command.kind)) {
      throw new CommentMovedError(`A ${String(state)} comment cannot be changed that way`)
    }

    return enqueue(database, {
      table: 'comments', recordId: commentId, kind: 'command', baseVersion: shownVersion, fields, at,
    })
  })
}

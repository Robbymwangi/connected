import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it } from 'vitest'
import { canApply, COMMENT_BODY_LIMIT, CommentMovedError, commentBodyProblem, commentCommandFields, enqueueCommentCommand, overlayCommentRow } from './commentCommands'
import { LocalDatabase, type LocalRecord } from './localDatabase'
import { entriesForRecord, type OutboxEntry } from './outbox'

const AT = '2026-10-12T08:00:00.000Z'
const stores: LocalDatabase[] = []

afterEach(async () => {
  for (const database of stores.splice(0)) {
    database.close()
    await database.delete()
  }
})

function createDatabase(): LocalDatabase {
  const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
  stores.push(database)
  return database
}

const comment = (over: Partial<LocalRecord> = {}): LocalRecord => ({
  id: 'k1', version: 2, studentId: 's1', year: 2026, term: 1, termClosureId: 't1', authorId: null, body: 'Generated.', state: 'draft', ...over,
})

describe('commentCommandFields', () => {
  it('builds each command with the actor, and an edit with its trimmed body', () => {
    expect(commentCommandFields({ kind: 'edit', byId: 'u1', body: '  Careful work.  ' })).toEqual({ edit: { byId: 'u1', body: 'Careful work.' } })
    expect(commentCommandFields({ kind: 'accept', byId: 'u1' })).toEqual({ accept: { byId: 'u1' } })
    expect(commentCommandFields({ kind: 'regenerate', byId: 'u1' })).toEqual({ regenerate: { byId: 'u1' } })
  })

  it('refuses a body the server would refuse: blank, too long, or holding a NUL', () => {
    expect(commentBodyProblem('   ')).not.toBeNull()
    expect(commentBodyProblem('x'.repeat(COMMENT_BODY_LIMIT))).toBeNull()
    expect(commentBodyProblem('x'.repeat(COMMENT_BODY_LIMIT + 1))).not.toBeNull()
    expect(commentBodyProblem('a\u0000b')).not.toBeNull()
    expect(() => commentCommandFields({ kind: 'edit', byId: 'u1', body: '' })).toThrow(RangeError)
  })

  it('allows each command only from the states the server takes it from', () => {
    expect(['pending', 'draft', 'failed', 'accepted'].map((state) => canApply(state as never, 'edit'))).toEqual([true, true, true, false])
    expect(['pending', 'draft', 'failed', 'accepted'].map((state) => canApply(state as never, 'accept'))).toEqual([false, true, false, false])
    expect(['pending', 'draft', 'failed', 'accepted'].map((state) => canApply(state as never, 'regenerate'))).toEqual([true, true, true, false])
  })
})

describe('enqueueCommentCommand', () => {
  it('queues the command at the version shown and leaves the row alone', async () => {
    const database = createDatabase()
    await database.comments.put(comment())

    await enqueueCommentCommand(database, 'k1', 2, { kind: 'accept', byId: 'u1' }, AT)

    expect(await entriesForRecord(database, 'comments', 'k1')).toMatchObject([
      { table: 'comments', recordId: 'k1', kind: 'command', baseVersion: 2, fields: { accept: { byId: 'u1' } }, state: 'queued' },
    ])
    expect(await database.comments.get('k1')).toEqual(comment())
  })

  it('judges the state with this device\'s earlier commands applied: an edit then an accept of a pending comment', async () => {
    const database = createDatabase()
    await database.comments.put(comment({ state: 'pending', body: null }))

    await expect(enqueueCommentCommand(database, 'k1', 2, { kind: 'accept', byId: 'u1' }, AT)).rejects.toBeInstanceOf(CommentMovedError)
    await enqueueCommentCommand(database, 'k1', 2, { kind: 'edit', byId: 'u1', body: 'Written by me.' }, AT)
    await enqueueCommentCommand(database, 'k1', 2, { kind: 'accept', byId: 'u1' }, AT)

    expect((await entriesForRecord(database, 'comments', 'k1')).map((entry) => [Object.keys(entry.fields)[0], entry.baseVersion])).toEqual([['edit', 2], ['accept', null]])
  })

  it.each([
    ['gone', null],
    ['withdrawn by an unlock', comment({ deletedAt: AT })],
    ['already accepted', comment({ state: 'accepted' })],
  ])('queues nothing for a comment that is %s', async (_case, row) => {
    const database = createDatabase()
    if (row) await database.comments.put(row)

    await expect(enqueueCommentCommand(database, 'k1', 2, { kind: 'regenerate', byId: 'u1' }, AT)).rejects.toBeInstanceOf(CommentMovedError)
    expect(await database.outbox.count()).toBe(0)
  })

  it('queues nothing at a version this device has not seen', async () => {
    const database = createDatabase()
    await database.comments.put(comment())

    await expect(enqueueCommentCommand(database, 'k1', 5, { kind: 'accept', byId: 'u1' }, AT)).rejects.toBeInstanceOf(CommentMovedError)
  })
})

describe('overlayCommentRow', () => {
  const entry = (fields: Record<string, unknown>, over: Partial<OutboxEntry> = {}): OutboxEntry => ({
    seq: 1, id: crypto.randomUUID(), table: 'comments', recordId: 'k1', kind: 'command', baseVersion: 2, fields, at: AT, state: 'queued', ...over,
  })

  it('shows an edit as a draft by the editor, an accept as accepted, and a regenerate as pending and empty', () => {
    expect(overlayCommentRow(comment({ state: 'failed', body: null }), [entry({ edit: { byId: 'u1', body: 'Mine.' } })]))
      .toMatchObject({ local: true, row: { state: 'draft', body: 'Mine.', authorId: 'u1' } })
    expect(overlayCommentRow(comment(), [entry({ accept: { byId: 'u1' } })]))
      .toMatchObject({ local: true, row: { state: 'accepted', body: 'Generated.', authorId: 'u1' } })
    expect(overlayCommentRow(comment(), [entry({ regenerate: { byId: 'u1' } })]))
      .toMatchObject({ local: true, row: { state: 'pending', body: null, authorId: null } })
  })

  it('keeps an answered command until the row reaches its version, then shows the row', () => {
    const acked = entry({ accept: { byId: 'u1' } }, { state: 'acked', ackVersion: 3 })

    expect(overlayCommentRow(comment(), [acked])).toMatchObject({ local: true, version: 3, row: { state: 'accepted' } })
    expect(overlayCommentRow(comment({ version: 3, state: 'accepted', authorId: 'u1' }), [acked])).toMatchObject({ local: false, version: 3 })
  })

  it('ignores refused commands', () => {
    expect(overlayCommentRow(comment(), [entry({ accept: { byId: 'u1' } }, { state: 'failed' })])).toMatchObject({ local: false, row: comment() })
  })
})

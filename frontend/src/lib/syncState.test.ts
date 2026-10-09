import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it } from 'vitest'
import { LocalDatabase } from './localDatabase'
import type { OutboxEntry } from './outbox'
import { describeSync, plural, readSyncRows, summarizeSync, type SyncRows } from './syncState'
import { syncLastSuccessKey, type RunnerStatus } from './syncRunner'
import { syncNoticesKey } from './syncPush'

const NOW = new Date('2026-10-09T12:00:00.000Z')
const idle: RunnerStatus = { phase: 'idle', failure: null, message: null, nextAttemptAt: null }
const rows = (over: Partial<SyncRows> = {}): SyncRows => ({
  queued: 0, sent: 0, conflict: 0, failed: 0, notices: [], lastSuccessAt: null, ...over,
})
const describeOf = (over: Partial<SyncRows>, status: RunnerStatus = idle) => describeSync(summarizeSync(rows(over), status), NOW)

describe('plural', () => {
  it('counts changes', () => {
    expect(plural(1)).toBe('1 change')
    expect(plural(0)).toBe('0 changes')
    expect(plural(3)).toBe('3 changes')
  })
})

describe('summarizeSync', () => {
  it('is loading until the first read', () => {
    expect(summarizeSync(null, idle)).toMatchObject({ status: 'loading', pending: 0, conflict: 0, failed: 0, lastSyncedAt: null })
  })

  it('counts queued and sent as pending, and reads the last success and the retry time', () => {
    const state = summarizeSync(
      rows({ queued: 2, sent: 1, conflict: 1, failed: 2, lastSuccessAt: '2026-10-09T10:00:00.000Z' }),
      { phase: 'backoff', failure: 'retry', message: 'x', nextAttemptAt: NOW.getTime() + 5_000 },
    )

    expect(state).toMatchObject({ status: 'ready', pending: 3, conflict: 1, failed: 2, phase: 'backoff', failure: 'retry' })
    expect(state.lastSyncedAt?.toISOString()).toBe('2026-10-09T10:00:00.000Z')
    expect(state.nextAttemptAt?.getTime()).toBe(NOW.getTime() + 5_000)
  })

  it('treats an unreadable last-success value as never synced', () => {
    expect(summarizeSync(rows({ lastSuccessAt: 'not a date' }), idle).lastSyncedAt).toBeNull()
  })
})

describe('describeSync', () => {
  it('says nothing while loading', () => {
    expect(describeSync(summarizeSync(null, idle), NOW)).toEqual({ category: 'loading', text: '', announcement: '' })
  })

  it('says it has never synced when nothing has succeeded and nothing waits', () => {
    expect(describeOf({})).toMatchObject({ category: 'never', text: 'Not synced yet', announcement: 'Not synced yet' })
  })

  it('says when it last synced, in coarse relative time, and announces without the time', () => {
    const synced = describeOf({ lastSuccessAt: '2026-10-09T10:00:00.000Z' })

    expect(synced).toMatchObject({ category: 'synced', text: 'Synced 2h ago', announcement: 'All changes synced' })
  })

  it('says how many changes wait', () => {
    expect(describeOf({ queued: 2, sent: 1, lastSuccessAt: '2026-10-09T10:00:00.000Z' })).toMatchObject({
      category: 'waiting', text: '3 changes waiting', announcement: '3 changes waiting',
    })
    expect(describeOf({ queued: 1 }).text).toBe('1 change waiting')
  })

  it('says it is syncing only while a request is under way and something is pending', () => {
    const working = (phase: 'pushing' | 'pulling'): RunnerStatus => ({ ...idle, phase })

    expect(describeOf({ queued: 3 }, working('pushing'))).toMatchObject({ category: 'syncing', text: 'Syncing 3 changes' })
    expect(describeOf({ sent: 1 }, working('pulling'))).toMatchObject({ category: 'syncing', text: 'Syncing 1 change' })
    expect(describeOf({ lastSuccessAt: '2026-10-09T11:59:30.000Z' }, working('pulling'))).toMatchObject({ category: 'synced', text: 'Synced just now' })
  })

  it('keeps waiting while a retry is pending', () => {
    const backoff: RunnerStatus = { phase: 'backoff', failure: 'retry', message: 'x', nextAttemptAt: NOW.getTime() + 2_000 }

    expect(describeOf({ queued: 2 }, backoff)).toMatchObject({ category: 'waiting', text: '2 changes waiting' })
  })

  it('asks for review when a conflict or a refused change needs a person, ahead of waiting', () => {
    expect(describeOf({ conflict: 1, queued: 4 })).toMatchObject({ category: 'attention', text: '1 change needs review' })
    expect(describeOf({ conflict: 1, failed: 2 })).toMatchObject({ category: 'attention', text: '3 changes need review', announcement: '3 changes need review' })
    expect(describeOf({ failed: 1 }).category).toBe('attention')
  })

  it('says sync stopped for a defect or a lapsed sign-in, ahead of everything', () => {
    const defect: RunnerStatus = { phase: 'error', failure: 'defect', message: 'x', nextAttemptAt: null }
    const reauth: RunnerStatus = { phase: 'error', failure: 'reauth', message: 'x', nextAttemptAt: null }

    expect(describeOf({ queued: 2, conflict: 1 }, defect)).toMatchObject({ category: 'stopped', text: 'Sync stopped', announcement: 'Sync stopped' })
    expect(describeOf({}, reauth).category).toBe('stopped')
  })

  it('never mentions the connection', () => {
    const everything: Partial<SyncRows>[] = [{}, { queued: 1 }, { conflict: 1 }, { lastSuccessAt: '2026-10-09T10:00:00.000Z' }]
    for (const over of everything) expect(describeOf(over).text).not.toMatch(/online|offline|connect|network/i)
  })
})

describe('readSyncRows', () => {
  const stores: LocalDatabase[] = []
  afterEach(async () => {
    for (const database of stores.splice(0)) {
      database.close()
      await database.delete()
    }
  })
  const entry = (state: OutboxEntry['state'], recordId: string): OutboxEntry => ({
    id: crypto.randomUUID(), table: 'marks', recordId, kind: 'patch', baseVersion: 1, fields: { score: 1 }, at: 'x', state,
  })

  it('counts the outbox by state and reads the notices and the last success', async () => {
    const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
    stores.push(database)
    await database.outbox.bulkAdd([
      entry('queued', 'a'), entry('queued', 'b'), entry('sent', 'c'), entry('conflict', 'd'), entry('failed', 'e'), entry('acked', 'f'),
    ])
    const notice = { id: 'n1', table: 'assessments', recordId: 'x', kind: 'conflict', sent: {}, current: null, at: 'x' }
    await database.metadata.bulkPut([
      { key: syncNoticesKey, value: [notice] },
      { key: syncLastSuccessKey, value: '2026-10-09T10:00:00.000Z' },
    ])

    expect(await readSyncRows(database)).toEqual({
      queued: 2, sent: 1, conflict: 1, failed: 1, notices: [notice], lastSuccessAt: '2026-10-09T10:00:00.000Z',
    })
  })

  describe('a mark conflict that a resolution is already on its way for', () => {
    const conflictEntry = (conflictId: string, recordId: string): OutboxEntry => ({
      ...entry('conflict', recordId), conflictId,
    })
    const command = (state: OutboxEntry['state'], fields: Record<string, unknown>, conflictId = 'c1'): OutboxEntry => ({
      id: crypto.randomUUID(), table: 'conflicts', recordId: conflictId, kind: 'command', baseVersion: 1, fields, at: 'x', state,
    })
    const resolution = { resolution: { kind: 'self', byId: 'u1' } }

    it.each([['queued'], ['sent'], ['acked']] as const)('no longer asks for review once a %s resolution exists', async (state) => {
      const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
      stores.push(database)
      await database.outbox.bulkAdd([conflictEntry('c1', 'a'), conflictEntry('c2', 'b'), command(state, resolution)])

      const rows = await readSyncRows(database)

      expect(rows.conflict).toBe(1)
    })

    it('still asks for review when the command is only a proposal or a referral, or was refused', async () => {
      const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
      stores.push(database)
      await database.outbox.bulkAdd([
        conflictEntry('c1', 'a'),
        command('queued', { proposal: { byId: 'u1' } }),
        command('sent', { referral: { byId: 'u1' } }),
        command('failed', resolution),
      ])

      expect((await readSyncRows(database)).conflict).toBe(1)
    })

    it('counts a queued or sent command as pending, and a refused one as needing review', async () => {
      const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
      stores.push(database)
      await database.outbox.bulkAdd([command('queued', resolution), command('sent', resolution), command('acked', resolution), command('failed', resolution)])

      expect(await readSyncRows(database)).toMatchObject({ queued: 1, sent: 1, failed: 1, conflict: 0 })
    })
  })

  it('reads an empty database as nothing waiting and never synced', async () => {
    const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
    stores.push(database)

    expect(await readSyncRows(database)).toEqual({ queued: 0, sent: 0, conflict: 0, failed: 0, notices: [], lastSuccessAt: null })
  })

  it('ignores stored values of the wrong shape', async () => {
    const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
    stores.push(database)
    await database.metadata.bulkPut([{ key: syncNoticesKey, value: 'nope' }, { key: syncLastSuccessKey, value: 42 }])

    expect(await readSyncRows(database)).toMatchObject({ notices: [], lastSuccessAt: null })
  })
})

import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { assertDisplayFlags } from './displayFlags.testing'
import { commandFields, effectiveVersion, enqueueConflictCommand, overlayConflictRow, reportRequest, requestConflictCommand, type ConflictCommand } from './conflictCommands'
import type { ActiveConflict } from '../fixtures/conflicts'
import { LocalDatabase, type LocalRecord } from './localDatabase'
import type { OutboxEntry } from './outbox'
import { entriesForRecord } from './outbox'
import { ABSENT, EMPTY, score, type Mark } from './grading'

const AT = '2026-10-09T08:00:00.000Z'
const LATER = '2026-10-09T09:00:00.000Z'
const me = { id: 'u1', name: 'Jane Teacher' }
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

const side = { kind: 'side' as const, editId: 'e1' }

describe('commandFields', () => {
  it('builds the proposal shape: ids only, a choice, and a note', () => {
    expect(commandFields({ kind: 'propose', byId: 'u1', choice: side, note: 'mine is right' })).toEqual({
      proposal: { byId: 'u1', choice: { kind: 'side', editId: 'e1' }, note: 'mine is right' },
    })
  })

  it('writes a corrected mark as a score or as absent, and never as empty', () => {
    const propose = (mark: Mark) =>
      commandFields({ kind: 'propose', byId: 'u1', choice: { kind: 'corrected', mark }, note: 'n' })

    expect(propose(score(9))).toEqual({ proposal: { byId: 'u1', choice: { kind: 'corrected', mark: { kind: 'score', value: 9 } }, note: 'n' } })
    expect(propose(ABSENT)).toEqual({ proposal: { byId: 'u1', choice: { kind: 'corrected', mark: { kind: 'absent' } }, note: 'n' } })
    expect(() => propose(EMPTY)).toThrow()
  })

  it('builds the referral and the acceptance, which names only the proposer', () => {
    expect(commandFields({ kind: 'refer', byId: 'u1' })).toEqual({ referral: { byId: 'u1' } })
    expect(commandFields({ kind: 'accept', proposedById: 'u2', acceptedById: 'u1' })).toEqual({
      resolution: { kind: 'agreed', proposedById: 'u2', acceptedById: 'u1' },
    })
  })

  it('builds a self resolution with no note key at all, and a moderated one with its note', () => {
    expect(commandFields({ kind: 'resolve', resolution: 'self', byId: 'u1', choice: side, note: 'ignored' })).toEqual({
      resolution: { kind: 'self', byId: 'u1', choice: { kind: 'side', editId: 'e1' } },
    })
    expect(commandFields({ kind: 'resolve', resolution: 'moderated', byId: 'u3', choice: side, note: 'moderated' })).toEqual({
      resolution: { kind: 'moderated', byId: 'u3', choice: { kind: 'side', editId: 'e1' }, note: 'moderated' },
    })
    expect(() => commandFields({ kind: 'resolve', resolution: 'moderated', byId: 'u3', choice: side })).toThrow()
    expect(() => commandFields({ kind: 'resolve', resolution: 'moderated', byId: 'u3', choice: side, note: '  ' })).toThrow()
  })

  it('never carries a time or a display name', () => {
    const commands: ConflictCommand[] = [
      { kind: 'propose', byId: 'u1', choice: side, note: 'n' },
      { kind: 'refer', byId: 'u1' },
      { kind: 'accept', proposedById: 'u2', acceptedById: 'u1' },
      { kind: 'resolve', resolution: 'moderated', byId: 'u3', choice: side, note: 'n' },
    ]
    for (const command of commands) {
      expect(JSON.stringify(commandFields(command))).not.toMatch(/receivedAt|"at"|"by":|"who"|proposedBy"|acceptedBy"/)
    }
  })
})

const row = (over: Partial<LocalRecord> = {}): LocalRecord => ({
  id: 'c1', version: 2, markId: 'm1', proposals: [], referral: null, resolution: null, resolvedAt: null, ...over,
})
const command = (over: Partial<OutboxEntry> = {}): OutboxEntry => ({
  id: crypto.randomUUID(), table: 'conflicts', recordId: 'c1', kind: 'command', baseVersion: 2,
  fields: { referral: { byId: 'u1' } }, at: AT, state: 'queued', ...over,
})

describe('effectiveVersion', () => {
  it('is the row version, raised by commands the server has answered but the pull has not yet delivered', () => {
    expect(effectiveVersion(row(), [])).toBe(2)
    expect(effectiveVersion(row(), [command({ state: 'acked', ackVersion: 4 }), command({ state: 'acked', ackVersion: 3 })])).toBe(4)
    expect(effectiveVersion(row({ version: 5 }), [command({ state: 'acked', ackVersion: 4 })])).toBe(5)
  })

  it('ignores commands that have not been answered', () => {
    expect(effectiveVersion(row(), [command({ state: 'queued' }), command({ state: 'sent' }), command({ state: 'failed', ackVersion: 9 })])).toBe(2)
  })
})

describe('enqueueConflictCommand', () => {
  const refer: ConflictCommand = { kind: 'refer', byId: 'u1' }

  it('queues the command against the version the teacher was shown, and writes nothing else', async () => {
    const database = createDatabase()
    await database.conflicts.put(row())

    const entry = await enqueueConflictCommand(database, 'c1', 2, refer, AT)

    expect(entry).toMatchObject({ table: 'conflicts', recordId: 'c1', kind: 'command', baseVersion: 2, state: 'queued', fields: { referral: { byId: 'u1' } }, at: AT })
    expect(await database.conflicts.get('c1')).toEqual(row())
    expect(await database.outbox.count()).toBe(1)
    expect(await database.marks.count()).toBe(0)
    await assertDisplayFlags(database)
  })

  it('never enqueues a mark patch for a resolution', async () => {
    const database = createDatabase()
    await database.conflicts.put(row())

    await enqueueConflictCommand(database, 'c1', 2, { kind: 'resolve', resolution: 'self', byId: 'u1', choice: side }, AT)

    expect((await database.outbox.toArray()).map((entry) => entry.table)).toEqual(['conflicts'])
  })

  it('gives a second command no base while the first is open', async () => {
    const database = createDatabase()
    await database.conflicts.put(row())

    await enqueueConflictCommand(database, 'c1', 2, { kind: 'propose', byId: 'u1', choice: side, note: 'n' }, AT)
    const second = await enqueueConflictCommand(database, 'c1', 2, refer, LATER)

    expect(second.baseVersion).toBeNull()
    expect((await entriesForRecord(database, 'conflicts', 'c1')).map((entry) => entry.baseVersion)).toEqual([2, null])
  })

  it('bases a command written after an answer on that answer, before the pull has caught up', async () => {
    const database = createDatabase()
    await database.conflicts.put(row())
    await database.outbox.add(command({ state: 'acked', ackVersion: 3 }))

    const entry = await enqueueConflictCommand(database, 'c1', 3, refer, LATER)

    expect(entry.baseVersion).toBe(3)
  })

  it('sends a command based on an older view as it is, so the server can say it is stale', async () => {
    const database = createDatabase()
    await database.conflicts.put(row({ version: 4 }))

    const entry = await enqueueConflictCommand(database, 'c1', 2, refer, AT)

    expect(entry.baseVersion).toBe(2)
  })

  it.each([
    ['is not on this device', async () => undefined],
    ['has been deleted', async (database: LocalDatabase) => { await database.conflicts.put(row({ deletedAt: AT })) }],
    ['is already resolved', async (database: LocalDatabase) => { await database.conflicts.put(row({ resolution: { kind: 'self' }, resolvedAt: AT })) }],
  ])('refuses a conflict that %s', async (_name, setup) => {
    const database = createDatabase()
    await setup(database)

    await expect(enqueueConflictCommand(database, 'c1', 2, refer, AT)).rejects.toThrow(/conflict/i)
    expect(await database.outbox.count()).toBe(0)
  })

  it('refuses a base the device could not have seen', async () => {
    const database = createDatabase()
    await database.conflicts.put(row())

    await expect(enqueueConflictCommand(database, 'c1', 3, refer, AT)).rejects.toThrow(/version/i)
    expect(await database.outbox.count()).toBe(0)
  })
})

describe('overlayConflictRow', () => {
  const proposalRow = { byId: 'u2', by: 'Ms. Akinyi', choice: side, note: 'theirs', at: AT, receivedAt: AT }
  const overlay = (r: LocalRecord, commands: OutboxEntry[]) => overlayConflictRow(r, commands, me)

  it('is the row itself, and not local, when there is nothing to show', () => {
    expect(overlay(row(), [])).toEqual({ row: row(), local: false, version: 2 })
  })

  it('appends a queued or sent proposal under the signed-in teacher', () => {
    const c = command({ fields: { proposal: { byId: 'u1', choice: { kind: 'corrected', mark: { kind: 'score', value: 9 } }, note: 'why' } } })

    const result = overlay(row(), [c, command({ state: 'sent', fields: { referral: { byId: 'u1' } } })])

    expect(result.local).toBe(true)
    expect(result.row.proposals).toEqual([{ byId: 'u1', by: 'Jane Teacher', choice: { kind: 'corrected', mark: { kind: 'score', value: 9 } }, note: 'why', at: AT }])
  })

  it('sets a party referral, or a rounds referral once two proposals exist', () => {
    const party = overlay(row(), [command()])
    const rounds = overlay(row({ proposals: [proposalRow, { ...proposalRow, byId: 'u1' }] }), [command()])

    expect(party.row.referral).toEqual({ reason: 'party', byId: 'u1', by: 'Jane Teacher', at: AT })
    expect(rounds.row.referral).toEqual({ reason: 'rounds', at: AT })
  })

  it('shows an acceptance as agreed, copying the choice and the note from the proposal it accepts', () => {
    const accept = command({ fields: { resolution: { kind: 'agreed', proposedById: 'u2', acceptedById: 'u1' } } })

    const result = overlay(row({ proposals: [proposalRow] }), [accept])

    expect(result.row.resolution).toEqual({
      kind: 'agreed', proposedById: 'u2', proposedBy: 'Ms. Akinyi', acceptedById: 'u1', acceptedBy: 'Jane Teacher', choice: side, note: 'theirs',
    })
    expect(result.row.resolvedAt).toBe(AT)
    expect(result.local).toBe(true)
  })

  it('shows a self or moderated resolution with the actor and, for moderated, the note', () => {
    const self = overlay(row(), [command({ fields: { resolution: { kind: 'self', byId: 'u1', choice: side } } })])
    const moderated = overlay(row(), [command({ fields: { resolution: { kind: 'moderated', byId: 'u1', choice: side, note: 'blind' } } })])

    expect(self.row.resolution).toEqual({ kind: 'self', byId: 'u1', by: 'Jane Teacher', choice: side })
    expect(moderated.row.resolution).toEqual({ kind: 'moderated', byId: 'u1', by: 'Jane Teacher', choice: side, note: 'blind' })
  })

  it('does not invent an agreement when there is no proposal for it to accept, and is not local for it', () => {
    const accept = command({ fields: { resolution: { kind: 'agreed', proposedById: 'u2', acceptedById: 'u1' } } })

    expect(overlay(row(), [accept])).toEqual({ row: row(), local: false, version: 2 })
  })

  it('still applies the commands that can be applied around one that cannot', () => {
    const accept = command({ seq: 1, fields: { resolution: { kind: 'agreed', proposedById: 'u2', acceptedById: 'u1' } } })
    const refer = command({ seq: 2, fields: { referral: { byId: 'u1' } } })

    const result = overlay(row(), [accept, refer])

    expect(result.local).toBe(true)
    expect(result.row.resolution).toBeNull()
    expect(result.row.referral).toMatchObject({ reason: 'party' })
  })

  it('applies commands in the order they were written', () => {
    const proposal = command({ fields: { proposal: { byId: 'u1', choice: side, note: 'n' } } })
    const referral = command({ fields: { referral: { byId: 'u1' } } })

    const result = overlay(row(), [{ ...proposal, seq: 1 }, { ...referral, seq: 2 }])

    expect(result.row.proposals).toHaveLength(1)
    expect(result.row.referral).toMatchObject({ reason: 'party' })
  })

  it('keeps an acknowledged command only until the pull has delivered the version it was answered at', () => {
    const acked = command({ state: 'acked', ackVersion: 3 })

    expect(overlay(row({ version: 2 }), [acked]).local).toBe(true)
    expect(overlay(row({ version: 2 }), [acked]).version).toBe(3)
    expect(overlay(row({ version: 3 }), [acked])).toEqual({ row: row({ version: 3 }), local: false, version: 3 })
  })

  it('ignores a failed command', () => {
    expect(overlay(row(), [command({ state: 'failed', reason: 'no' })])).toEqual({ row: row(), local: false, version: 2 })
  })

  it('lets the server win once the row is resolved, however it was resolved', () => {
    const resolved = row({ resolution: { kind: 'moderated', byId: 'u3' }, resolvedAt: LATER })

    expect(overlay(resolved, [command({ fields: { resolution: { kind: 'self', byId: 'u1', choice: side } } })])).toEqual({
      row: resolved, local: false, version: 2,
    })
  })

  it('survives closing and reopening the database, because it is derived from stored entries', async () => {
    const name = `connected-test-${crypto.randomUUID()}`
    const first = new LocalDatabase(name)
    await first.conflicts.put(row())
    await enqueueConflictCommand(first, 'c1', 2, { kind: 'propose', byId: 'u1', choice: side, note: 'n' }, AT)
    first.close()

    const second = new LocalDatabase(name)
    stores.push(second)
    const stored = await entriesForRecord(second, 'conflicts', 'c1')

    expect(overlayConflictRow((await second.conflicts.get('c1')) as LocalRecord, stored, me).row.proposals).toHaveLength(1)
  })
})


describe('requestConflictCommand', () => {
  const party = { id: 'u1', name: 'Jane Teacher', moderatedSubjects: [] as string[] }
  const view = (over: Partial<ActiveConflict> = {}): ActiveConflict => ({
    id: 'c1', assessmentId: 'a1', subjectId: 'subject-1', baseVersion: 1, conflictVersion: 2, studentId: 's1', criterionId: 'k1',
    student: 'S', criterion: 'C', assessment: 'A',
    mine: { editId: 'e-m', userId: 'u1', who: 'Jane Teacher', mark: score(14), at: AT },
    theirs: { editId: 'e-t', userId: 'u2', who: 'Ms. Akinyi', mark: score(11), at: AT },
    proposals: [],
    ...over,
  })
  const propose = { action: 'propose' as const, choice: { kind: 'side' as const, editId: 'e-t' }, note: 'because' }

  it('queues what the policy allows, against the version shown, and says so', async () => {
    const database = createDatabase()
    await database.conflicts.put(row())

    const queued = await requestConflictCommand(database, view(), propose, party, 20, AT)

    expect(queued).toBe(true)
    expect((await entriesForRecord(database, 'conflicts', 'c1'))[0]).toMatchObject({
      baseVersion: 2, fields: { proposal: { byId: 'u1', choice: { kind: 'side', editId: 'e-t' }, note: 'because' } },
    })
  })

  it('says nothing was queued when the conflict is not in view', async () => {
    const database = createDatabase()

    expect(await requestConflictCommand(database, undefined, propose, party, 20, AT)).toBe(false)
    expect(await database.outbox.count()).toBe(0)
  })

  it('says nothing was queued when the policy refuses, without touching the database', async () => {
    const database = createDatabase()
    await database.conflicts.put(row())

    expect(await requestConflictCommand(database, view(), { ...propose, note: '' }, party, 20, AT)).toBe(false)
    expect(await requestConflictCommand(database, view(), { action: 'resolve', choice: propose.choice, note: 'n' }, party, 20, AT)).toBe(false)
    expect(await database.outbox.count()).toBe(0)
  })

  it('says nothing was queued when the conflict was resolved while the teacher was deciding', async () => {
    const database = createDatabase()
    await database.conflicts.put(row({ resolution: { kind: 'moderated', byId: 'u3' }, resolvedAt: LATER }))

    expect(await requestConflictCommand(database, view(), propose, party, 20, AT)).toBe(false)
    expect(await database.outbox.count()).toBe(0)
  })
})


describe('reportRequest', () => {
  const settled = () => new Promise<void>((resolve) => setTimeout(resolve, 0))

  it('announces a command that was queued, and nothing else', async () => {
    const report = { queued: vi.fn(), failed: vi.fn() }

    reportRequest(Promise.resolve(true), report)
    await settled()

    expect(report.queued).toHaveBeenCalledTimes(1)
    expect(report.failed).not.toHaveBeenCalled()
  })

  it('says nothing when nothing was queued because the conflict moved on', async () => {
    const report = { queued: vi.fn(), failed: vi.fn() }

    reportRequest(Promise.resolve(false), report)
    await settled()

    expect(report.queued).not.toHaveBeenCalled()
    expect(report.failed).not.toHaveBeenCalled()
  })

  it('reports a write that failed, rather than leaving the rejection unhandled', async () => {
    const report = { queued: vi.fn(), failed: vi.fn() }

    reportRequest(Promise.reject(new Error('quota')), report)
    await settled()

    expect(report.failed).toHaveBeenCalledTimes(1)
    expect(report.queued).not.toHaveBeenCalled()
  })

  it('needs no announcement for a request whose success is quiet', async () => {
    const failed = vi.fn()

    reportRequest(Promise.resolve(true), { failed })
    await settled()

    expect(failed).not.toHaveBeenCalled()
  })
})

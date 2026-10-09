import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { LocalDatabase } from './localDatabase'
import { enqueue, entriesForRecord, type OutboxEntry } from './outbox'

const ASSESSMENT = '11111111-1111-4111-8111-111111111111'
const MARK = '22222222-2222-4222-8222-222222222222'
const CONFLICT = '33333333-3333-4333-8333-333333333333'
const AT = '2026-10-09T08:00:00.000Z'
const LATER = '2026-10-09T08:05:00.000Z'

let database: LocalDatabase

beforeEach(() => {
  database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
})

afterEach(async () => {
  database.close()
  await database.delete()
})

function put(entry: Partial<OutboxEntry> & Pick<OutboxEntry, 'table' | 'recordId'>) {
  return database.outbox.add({
    id: crypto.randomUUID(),
    kind: 'patch',
    baseVersion: 1,
    fields: {},
    at: AT,
    state: 'queued',
    ...entry,
  })
}

describe('schema', () => {
  it('declares the outbox keyed by an ascending sequence with a unique mutation id', () => {
    expect(database.outbox.schema.primKey.name).toBe('seq')
    expect(database.outbox.schema.primKey.auto).toBe(true)
    expect(database.outbox.schema.idxByName.id.unique).toBe(true)
    expect(database.outbox.schema.idxByName['[table+recordId]']).toBeDefined()
  })
})

describe('enqueue a patch', () => {
  it('appends a queued entry carrying the base version the user saw and changed fields only', async () => {
    const entry = await enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, fields: { markKind: 'score', score: 14 }, at: AT,
    })

    expect(entry).toMatchObject({
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, state: 'queued',
      fields: { markKind: 'score', score: 14 }, at: AT,
    })
    expect(entry.id).toMatch(/^[0-9a-f-]{36}$/)
    expect(await entriesForRecord(database, 'marks', MARK)).toHaveLength(1)
  })

  it('coalesces into an unsent patch: earliest base, union of fields, newest value, same id and place', async () => {
    const first = await enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, fields: { markKind: 'score', score: 14 }, at: AT,
    })
    const second = await enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, fields: { score: 15, note: 'recheck' }, at: LATER,
    })

    const entries = await entriesForRecord(database, 'marks', MARK)
    expect(entries).toHaveLength(1)
    expect(second.id).toBe(first.id)
    expect(second.seq).toBe(first.seq)
    expect(entries[0]).toMatchObject({
      baseVersion: 6, fields: { markKind: 'score', score: 15, note: 'recheck' }, at: LATER, state: 'queued',
    })
  })

  it('keeps the earliest base even if a later edit reports a higher one', async () => {
    await enqueue(database, { table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 2, fields: { score: 1 }, at: AT })
    await enqueue(database, { table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 5, fields: { score: 2 }, at: LATER })

    expect((await entriesForRecord(database, 'marks', MARK))[0].baseVersion).toBe(2)
  })

  it('never coalesces into a sent entry: it is frozen, and the follow-up waits with a null base', async () => {
    const sent = await put({ table: 'marks', recordId: MARK, baseVersion: 6, fields: { score: 14 }, state: 'sent' })

    const next = await enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, fields: { score: 15 }, at: LATER,
    })

    const entries = await entriesForRecord(database, 'marks', MARK)
    expect(entries).toHaveLength(2)
    expect(entries[0]).toMatchObject({ seq: sent, state: 'sent', fields: { score: 14 }, baseVersion: 6 })
    expect(next).toMatchObject({ state: 'queued', baseVersion: null, fields: { score: 15 } })
  })

  it('keeps a null base when it coalesces into a follow-up that is still waiting', async () => {
    await put({ table: 'marks', recordId: MARK, baseVersion: 6, fields: { score: 14 }, state: 'sent' })
    await enqueue(database, { table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, fields: { score: 15 }, at: AT })
    await enqueue(database, { table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, fields: { score: 16 }, at: LATER })

    const entries = await entriesForRecord(database, 'marks', MARK)
    expect(entries).toHaveLength(2)
    expect(entries[1]).toMatchObject({ baseVersion: null, fields: { score: 16 } })
  })

  it('waits behind a conflicted entry too', async () => {
    await put({ table: 'marks', recordId: MARK, baseVersion: 6, fields: { score: 14 }, state: 'conflict' })

    const next = await enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, fields: { score: 15 }, at: AT,
    })

    expect(next.baseVersion).toBeNull()
  })

  it('ignores failed and acknowledged entries when deciding whether to wait', async () => {
    await put({ table: 'marks', recordId: MARK, baseVersion: 6, fields: { score: 14 }, state: 'failed' })

    const next = await enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 6, fields: { score: 15 }, at: AT,
    })

    expect(next.baseVersion).toBe(6)
  })

  it('keeps entries for different records separate', async () => {
    const other = '44444444-4444-4444-8444-444444444444'
    await enqueue(database, { table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 1, fields: { score: 1 }, at: AT })
    await enqueue(database, { table: 'marks', recordId: other, kind: 'patch', baseVersion: 1, fields: { score: 2 }, at: AT })

    expect(await entriesForRecord(database, 'marks', MARK)).toHaveLength(1)
    expect(await entriesForRecord(database, 'marks', other)).toHaveLength(1)
  })

  it('orders an assessment create before the marks written after it', async () => {
    const create = await enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'patch', baseVersion: 0, fields: { name: 'CAT 1' }, at: AT,
    })
    const mark = await enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 0, fields: { assessmentId: ASSESSMENT, score: 9 }, at: AT,
    })

    expect(create.seq!).toBeLessThan(mark.seq!)
  })

  it('accepts patches only for the tables a device may push', async () => {
    const other = '55555555-5555-4555-8555-555555555555'
    await expect(enqueue(database, {
      table: 'students', recordId: other, kind: 'patch', baseVersion: 1, fields: { name: 'x' }, at: AT,
    })).rejects.toThrow(/push/i)
    await expect(enqueue(database, {
      table: 'notifications', recordId: other, kind: 'patch', baseVersion: 1, fields: { title: 'x' }, at: AT,
    })).rejects.toThrow(/unread/i)
    await expect(enqueue(database, {
      table: 'notifications', recordId: other, kind: 'patch', baseVersion: 1, fields: { unread: false }, at: AT,
    })).resolves.toMatchObject({ fields: { unread: false } })
    expect(await entriesForRecord(database, 'students', other)).toHaveLength(0)
  })

  it('sends finalize fields only through a finalize entry, never in a patch', async () => {
    for (const fields of [{ status: 'finalized' }, { name: 'x', finalizedBy: 'u1' }, { finalizedAt: AT }]) {
      await expect(enqueue(database, {
        table: 'assessments', recordId: ASSESSMENT, kind: 'patch', baseVersion: 3, fields, at: AT,
      })).rejects.toThrow(/finalize/i)
    }
    expect(await entriesForRecord(database, 'assessments', ASSESSMENT)).toHaveLength(0)
  })

  it('rejects an empty patch and a patch on conflicts', async () => {
    await expect(enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 1, fields: {}, at: AT,
    })).rejects.toThrow()
    await expect(enqueue(database, {
      table: 'conflicts', recordId: CONFLICT, kind: 'patch', baseVersion: 1, fields: { x: 1 }, at: AT,
    })).rejects.toThrow()
  })
})

describe('enqueue a finalize', () => {
  const finalize = { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT }

  it('is its own trailing entry and is never folded into an earlier patch', async () => {
    await enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'patch', baseVersion: 3, fields: { name: 'Renamed' }, at: AT,
    })
    await enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'finalize', baseVersion: 3, fields: finalize, at: LATER,
    })

    const entries = await entriesForRecord(database, 'assessments', ASSESSMENT)
    expect(entries.map((entry) => entry.kind)).toEqual(['patch', 'finalize'])
    expect(entries[0].fields).toEqual({ name: 'Renamed' })
    expect(entries[1].fields).toEqual(finalize)
    expect(entries[1].baseVersion).toBeNull()
  })

  it('has its own base when nothing else is outstanding for the record', async () => {
    const entry = await enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'finalize', baseVersion: 4, fields: finalize, at: AT,
    })

    expect(entry.baseVersion).toBe(4)
  })

  it('waits on an unacknowledged create', async () => {
    await enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'patch', baseVersion: 0, fields: { name: 'CAT 1' }, at: AT,
    })
    const entry = await enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'finalize', baseVersion: 0, fields: finalize, at: AT,
    })

    expect(entry.baseVersion).toBeNull()
  })

  it('refuses a later patch to the record while the finalize is open, and leaves the finalize untouched', async () => {
    await enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'finalize', baseVersion: 4, fields: finalize, at: AT,
    })

    await expect(enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'patch', baseVersion: 4, fields: { name: 'Late' }, at: LATER,
    })).rejects.toThrow(/finalize/i)

    const entries = await entriesForRecord(database, 'assessments', ASSESSMENT)
    expect(entries).toHaveLength(1)
    expect(entries[0].fields).toEqual(finalize)
  })

  it('allows a patch again once the finalize has failed', async () => {
    await put({ table: 'assessments', recordId: ASSESSMENT, kind: 'finalize', baseVersion: 4, fields: finalize, state: 'failed' })

    const entry = await enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'patch', baseVersion: 4, fields: { name: 'Retry' }, at: LATER,
    })

    expect(entry.baseVersion).toBe(4)
  })

  it('accepts only the finalize fields', async () => {
    await expect(enqueue(database, {
      table: 'assessments', recordId: ASSESSMENT, kind: 'finalize', baseVersion: 4, fields: { ...finalize, name: 'x' }, at: AT,
    })).rejects.toThrow()
    await expect(enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'finalize', baseVersion: 4, fields: finalize, at: AT,
    })).rejects.toThrow()
  })
})

describe('enqueue a conflict command', () => {
  const proposal = { proposal: { byId: 'u1', choice: { kind: 'side', editId: 'e1' }, note: 'mine is right' } }
  const referral = { referral: { byId: 'u1' } }

  it('is never coalesced; the first keeps the conflict version the teacher saw', async () => {
    await enqueue(database, { table: 'conflicts', recordId: CONFLICT, kind: 'command', baseVersion: 2, fields: proposal, at: AT })
    await enqueue(database, { table: 'conflicts', recordId: CONFLICT, kind: 'command', baseVersion: 2, fields: referral, at: LATER })

    const entries = await entriesForRecord(database, 'conflicts', CONFLICT)
    expect(entries).toHaveLength(2)
    expect(entries.map((entry) => entry.fields)).toEqual([proposal, referral])
    expect(entries[0].baseVersion).toBe(2)
  })

  it('waits behind an open command, since the server bumps the conflict version when it answers', async () => {
    await enqueue(database, { table: 'conflicts', recordId: CONFLICT, kind: 'command', baseVersion: 2, fields: proposal, at: AT })
    await enqueue(database, { table: 'conflicts', recordId: CONFLICT, kind: 'command', baseVersion: 2, fields: referral, at: LATER })

    const entries = await entriesForRecord(database, 'conflicts', CONFLICT)
    expect(entries.map((entry) => entry.baseVersion)).toEqual([2, null])
  })

  it('does not wait behind an acknowledged command', async () => {
    await put({ table: 'conflicts', recordId: CONFLICT, kind: 'command', baseVersion: 2, fields: proposal, state: 'acked' })

    const entry = await enqueue(database, {
      table: 'conflicts', recordId: CONFLICT, kind: 'command', baseVersion: 3, fields: referral, at: LATER,
    })

    expect(entry.baseVersion).toBe(3)
  })

  it('accepts exactly one command key on the conflicts table', async () => {
    await expect(enqueue(database, {
      table: 'conflicts', recordId: CONFLICT, kind: 'command', baseVersion: 2, fields: { ...proposal, ...referral }, at: AT,
    })).rejects.toThrow()
    await expect(enqueue(database, {
      table: 'conflicts', recordId: CONFLICT, kind: 'command', baseVersion: 2, fields: { note: 'x' }, at: AT,
    })).rejects.toThrow()
    await expect(enqueue(database, {
      table: 'marks', recordId: MARK, kind: 'command', baseVersion: 2, fields: proposal, at: AT,
    })).rejects.toThrow()
  })
})

describe('enqueue inside the caller transaction', () => {
  it('rolls back with the record write when the transaction fails', async () => {
    await expect(database.transaction('rw', database.marks, database.outbox, async () => {
      await database.marks.put({ id: MARK, version: 0 })
      await enqueue(database, { table: 'marks', recordId: MARK, kind: 'patch', baseVersion: 0, fields: { score: 1 }, at: AT })
      throw new Error('abort')
    })).rejects.toThrow('abort')

    expect(await database.marks.get(MARK)).toBeUndefined()
    expect(await entriesForRecord(database, 'marks', MARK)).toHaveLength(0)
  })
})

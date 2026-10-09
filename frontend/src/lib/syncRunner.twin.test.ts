import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { assertDisplayFlags } from './displayFlags.testing'
import { LocalDatabase } from './localDatabase'
import { createAssessments, finalizeAssessmentRecord, writeMarkCells } from './localWrites'
import { score } from './grading'
import { createSyncRunner, syncLastSuccessKey, type RunnerStatus } from './syncRunner'
import { pullSync } from './syncPull'
import { pushSync } from './syncPush'

/* The build-plan 3.4 done-when, minus the browser: edits made with no connection,
   then a runner that reconciles them with no one clicking anything. The real writers,
   the real outbox, the real push and pull, and a stand-in server that records what it
   was sent. Real timers with zero delays: fake timers stall fake-indexeddb. */

const AT = '2026-10-09T08:00:00.000Z'
const CREATE = { classId: 'c1', subjectId: 'sub1', name: 'CAT 1', term: 'Term 1', year: 2025, date: '2025-05-12' }
const stores: LocalDatabase[] = []

afterEach(async () => {
  for (const database of stores.splice(0)) {
    database.close()
    await database.delete()
  }
})

const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200 })

type Wire = { id: string; table: string; recordId: string; baseVersion: number; fields: Record<string, unknown> }

function standInServer() {
  const versions = new Map<string, number>()
  const bodies: string[] = []
  const control = { failNextPost: false }
  const fetchImpl = vi.fn(async (_path: unknown, init?: RequestInit) => {
    if (init?.method !== 'POST') return json({ changes: [], cursor: 5, more: false })
    const raw = String(init.body)
    bodies.push(raw)
    if (control.failNextPost) {
      control.failNextPost = false
      throw new TypeError('Failed to fetch')
    }
    const entries = (JSON.parse(raw) as { entries: Wire[] }).entries
    return json({
      results: entries.map((entry) => {
        const version = (versions.get(entry.recordId) ?? 0) + 1
        versions.set(entry.recordId, version)
        return { id: entry.id, status: 'accepted', version }
      }),
    })
  })
  return { fetchImpl: fetchImpl as unknown as typeof fetch, bodies, control, entriesOf: () => bodies.map((raw) => (JSON.parse(raw) as { entries: Wire[] }).entries) }
}

async function until(check: () => Promise<boolean>) {
  for (let attempt = 0; attempt < 600; attempt++) {
    if (await check()) return
    await new Promise((resolve) => setTimeout(resolve, 10))
  }
  throw new Error('timed out waiting for the runner')
}

async function editedOffline(marks: number) {
  const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)
  stores.push(database)
  await createAssessments(database, [{
    record: { id: 'a1', version: 0, ...CREATE, status: 'scheduled', sync: 'pending' },
    createFields: CREATE,
  }], AT)
  await writeMarkCells(database, 'a1', Array.from({ length: marks }, (_, i) => ({
    id: `m${String(i).padStart(3, '0')}`, studentId: `s${i}`, criterionId: 'k1', cell: { mark: score(10), sync: 'local' as const },
  })), 'Teacher', AT)
  await finalizeAssessmentRecord(database, 'a1', 'u1', AT)
  return database
}

function runnerFor(database: LocalDatabase, server: ReturnType<typeof standInServer>) {
  let status: RunnerStatus = { phase: 'idle', failure: null, message: null, nextAttemptAt: null }
  const runner = createSyncRunner({
    token: 'tok',
    push: () => pushSync('tok', 'u1', server.fetchImpl, database),
    pull: () => pullSync('tok', 'u1', server.fetchImpl, database),
    onReauth: () => undefined,
    recordSuccess: async () => { await database.metadata.put({ key: syncLastSuccessKey, value: new Date().toISOString() }) },
    isReachable: () => true,
    isVisible: () => true,
    clock: { now: () => Date.now(), setTimer: (fn, ms) => setTimeout(fn, ms), clearTimer: (id) => clearTimeout(id as ReturnType<typeof setTimeout>) },
    status: { get: () => status, set: (next) => { status = next } },
    debounceMs: 0,
    delay: () => 0,
  })
  return { runner, status: () => status }
}

const drained = (database: LocalDatabase) => async () =>
  (await database.outbox.count()) === 0 && (await database.metadata.get(syncLastSuccessKey)) !== undefined

describe('reconciling offline edits with no one clicking', () => {
  it('sends the create and the first marks, then the rest with the finalize behind them, at most 100 to a request', async () => {
    const database = await editedOffline(150)
    const server = standInServer()
    const { runner, status } = runnerFor(database, server)

    runner.trigger('session')
    await until(drained(database))

    const rounds = server.entriesOf()
    expect(rounds).toHaveLength(2)
    expect(rounds.every((entries) => entries.length <= 100)).toBe(true)
    expect(rounds[0][0]).toMatchObject({ table: 'assessments', recordId: 'a1', baseVersion: 0, fields: CREATE })
    expect(rounds[0]).toHaveLength(100)
    expect(rounds[0].some((entry) => entry.fields.status === 'finalized')).toBe(false)
    expect(rounds[1].at(-1)).toMatchObject({ table: 'assessments', recordId: 'a1', fields: { status: 'finalized' }, baseVersion: 1 })
    expect(rounds.flat()).toHaveLength(152)
    expect(new Set(rounds.flat().map((entry) => entry.id)).size).toBe(152)

    expect(await database.assessments.get('a1')).toMatchObject({ version: 2, status: 'finalized' })
    expect(await database.assessments.get('a1')).not.toHaveProperty('sync')
    expect(await database.marks.get('m000')).toMatchObject({ version: 1, score: 10 })
    expect(status()).toMatchObject({ phase: 'idle', failure: null })
    await assertDisplayFlags(database)
    runner.dispose()
  })

  it('resends a request whose response was lost with an identical body, with nothing written in between', async () => {
    const database = await editedOffline(3)
    const server = standInServer()
    server.control.failNextPost = true
    const { runner } = runnerFor(database, server)

    runner.trigger('session')
    await until(drained(database))

    expect(server.bodies.length).toBeGreaterThanOrEqual(2)
    expect(server.bodies[1]).toBe(server.bodies[0])
    expect(await database.outbox.count()).toBe(0)
    expect(await database.marks.count()).toBe(3)
    await assertDisplayFlags(database)
    runner.dispose()
  })

  it('reaches the same end state when the runner is started again after everything is done', async () => {
    const database = await editedOffline(2)
    const server = standInServer()
    const { runner } = runnerFor(database, server)
    runner.trigger('session')
    await until(drained(database))
    const posts = server.bodies.length

    runner.trigger('reachable')
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(server.bodies).toHaveLength(posts)
    runner.dispose()
  })
})

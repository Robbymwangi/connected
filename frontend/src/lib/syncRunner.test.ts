import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api/client'
import {
  BACKOFF_MS,
  createSyncRunner,
  MAX_CYCLES,
  MAX_PUSH_ROUNDS,
  nextDelay,
  runSyncPass,
  type Clock,
  type RunReason,
  type RunnerStatus,
} from './syncRunner'
import type { SyncPushResult } from './syncPush'
import { SyncPushProtocolError } from './syncPush'

const counts = (over: Partial<SyncPushResult['settled']> = {}) => ({ accepted: 0, merged: 0, conflict: 0, forbidden: 0, invalid: 0, ...over })
const pushed = (sent: number, settled: Partial<SyncPushResult['settled']> = {}, held = 0): SyncPushResult => ({ sent, settled: counts(settled), held })
const reasons = (...items: RunReason[]) => new Set<RunReason>(items)

describe('nextDelay', () => {
  it('follows the ladder with no jitter at the midpoint, then holds the cap', () => {
    const mid = () => 0.5
    expect(Array.from({ length: BACKOFF_MS.length + 3 }, (_, attempt) => nextDelay(attempt, mid))).toEqual([
      ...BACKOFF_MS, 300_000, 300_000, 300_000,
    ])
    expect(BACKOFF_MS[0]).toBe(2_000)
  })

  it('jitters within plus and minus twenty percent', () => {
    expect(nextDelay(0, () => 0)).toBe(1_600)
    expect(nextDelay(0, () => 1)).toBe(2_400)
    expect(nextDelay(99, () => 1)).toBe(360_000)
  })
})

describe('runSyncPass', () => {
  const deps = (rounds: SyncPushResult[]) => {
    const queue = [...rounds]
    return {
      push: vi.fn(async () => queue.shift() ?? pushed(0)),
      pull: vi.fn(async () => undefined),
      onPhase: vi.fn(),
    }
  }

  it('stops pushing when a round sends nothing, and pulls for a session start', async () => {
    const d = deps([pushed(0)])

    const result = await runSyncPass(d, reasons('session'))

    expect(d.push).toHaveBeenCalledTimes(1)
    expect(d.pull).toHaveBeenCalledTimes(1)
    expect(result).toEqual({ morePush: false })
    expect(d.onPhase.mock.calls.map(([phase]) => phase)).toEqual(['pushing', 'pulling'])
  })

  it('keeps pushing while rounds send and settle entries', async () => {
    const d = deps([pushed(100, { accepted: 100 }), pushed(100, { accepted: 90, merged: 10 }), pushed(7, { accepted: 7 }), pushed(0)])

    await runSyncPass(d, reasons('session'))

    expect(d.push).toHaveBeenCalledTimes(4)
  })

  it('does not loop on held entries; only on what was sent', async () => {
    const d = deps([pushed(0, {}, 5)])

    await runSyncPass(d, reasons('tick'))

    expect(d.push).toHaveBeenCalledTimes(1)
  })

  it('stops when a round sent entries but settled none, since another tab answered them', async () => {
    const d = deps([pushed(3, {}), pushed(3, {})])

    const result = await runSyncPass(d, reasons('session'))

    expect(d.push).toHaveBeenCalledTimes(1)
    expect(result.morePush).toBe(false)
  })

  it('stops at the round cap and says more remains', async () => {
    const d = { ...deps([]), push: vi.fn(async () => pushed(100, { accepted: 100 })) }

    const result = await runSyncPass(d, reasons('session'))

    expect(d.push).toHaveBeenCalledTimes(MAX_PUSH_ROUNDS)
    expect(result.morePush).toBe(true)
  })

  it('does not pull for a debounced enqueue that settled nothing', async () => {
    const d = deps([pushed(0)])

    await runSyncPass(d, reasons('enqueue'))

    expect(d.pull).not.toHaveBeenCalled()
  })

  it.each([['accepted'], ['merged'], ['conflict']] as const)('pulls after an enqueue push that settled a %s, to fetch what only the server writes', async (status) => {
    const d = deps([pushed(1, { [status]: 1 }), pushed(0)])

    await runSyncPass(d, reasons('enqueue'))

    expect(d.pull).toHaveBeenCalledTimes(1)
  })

  it('does not pull after a refused entry alone', async () => {
    const d = deps([pushed(1, { invalid: 1 }), pushed(0)])

    await runSyncPass(d, reasons('enqueue'))

    expect(d.pull).not.toHaveBeenCalled()
  })

  it.each([['reachable'], ['visible'], ['tick'], ['session']] as const)('pulls for a %s trigger', async (reason) => {
    const d = deps([pushed(0)])

    await runSyncPass(d, reasons(reason))

    expect(d.pull).toHaveBeenCalledTimes(1)
  })

  it('lets a push failure through without pulling', async () => {
    const d = { ...deps([]), push: vi.fn().mockRejectedValue(new TypeError('offline')) }

    await expect(runSyncPass(d, reasons('session'))).rejects.toBeInstanceOf(TypeError)
    expect(d.pull).not.toHaveBeenCalled()
  })

  it('lets a pull failure through', async () => {
    const d = { ...deps([pushed(0)]), pull: vi.fn().mockRejectedValue(new Error('Invalid GET /sync response')) }

    await expect(runSyncPass(d, reasons('session'))).rejects.toThrow('Invalid GET /sync response')
  })
})

/* A clock the test drives by hand, so the runner is exercised without real time and
   without fake timers (which stall fake-indexeddb). */
function fakeClock() {
  let now = 1_000_000
  let next = 1
  const timers = new Map<number, { at: number; fn: () => void }>()
  const clock: Clock = {
    now: () => now,
    setTimer: (fn, ms) => {
      const id = next++
      timers.set(id, { at: now + ms, fn })
      return id
    },
    clearTimer: (id) => { timers.delete(id as number) },
  }
  return {
    clock,
    pendingTimers: () => timers.size,
    async advance(ms: number) {
      const target = now + ms
      for (;;) {
        const due = [...timers.entries()].filter(([, timer]) => timer.at <= target).sort((a, b) => a[1].at - b[1].at)[0]
        if (!due) break
        timers.delete(due[0])
        now = due[1].at
        due[1].fn()
        await flush()
      }
      now = target
    },
  }
}

const flush = () => new Promise<void>((resolve) => setTimeout(resolve, 0))

function harness(options: {
  push?: () => Promise<SyncPushResult>
  pull?: () => Promise<unknown>
  reachable?: boolean
  visible?: boolean
  lock?: Parameters<typeof createSyncRunner>[0]['lock']
} = {}) {
  const time = fakeClock()
  const statuses: RunnerStatus[] = []
  let current: RunnerStatus = { phase: 'idle', failure: null, message: null, nextAttemptAt: null }
  const state = { reachable: options.reachable ?? true, visible: options.visible ?? true }
  const push = vi.fn(options.push ?? (async () => pushed(0)))
  const pull = vi.fn(options.pull ?? (async () => undefined))
  const onReauth = vi.fn()
  const recordSuccess = vi.fn(async () => undefined)
  const runner = createSyncRunner({
    token: 'tok',
    push,
    pull,
    onReauth,
    recordSuccess,
    isReachable: () => state.reachable,
    isVisible: () => state.visible,
    clock: time.clock,
    random: () => 0.5,
    lock: options.lock,
    status: { get: () => current, set: (next) => { current = next; statuses.push(next) } },
  })
  return { ...time, runner, push, pull, onReauth, recordSuccess, state, statuses, status: () => current }
}

describe('createSyncRunner', () => {
  it('runs one pass for a session start and records the success', async () => {
    const h = harness()

    h.runner.trigger('session')
    await flush()

    expect(h.push).toHaveBeenCalledTimes(1)
    expect(h.pull).toHaveBeenCalledTimes(1)
    expect(h.recordSuccess).toHaveBeenCalledTimes(1)
    expect(h.status()).toMatchObject({ phase: 'idle', failure: null })
    expect(h.statuses.map((status) => status.phase)).toEqual(['pushing', 'pulling', 'idle'])
  })

  it('reruns once, with the merged reasons, when a trigger arrives mid-run', async () => {
    let release!: () => void
    const gate = new Promise<void>((resolve) => { release = resolve })
    let calls = 0
    const h = harness({ push: async () => { if (calls++ === 0) await gate; return pushed(0) } })

    h.runner.trigger('session')
    await flush()
    h.runner.trigger('reachable')
    h.runner.trigger('visible')
    release()
    await flush()
    await flush()

    expect(h.push).toHaveBeenCalledTimes(2)
    expect(h.pull).toHaveBeenCalledTimes(2)
  })

  it('caps the reruns of one wake and schedules the rest instead of looping', async () => {
    const h = harness({ push: async () => pushed(100, { accepted: 100 }) })

    h.runner.trigger('session')
    await flush()

    expect(h.push).toHaveBeenCalledTimes(MAX_CYCLES * MAX_PUSH_ROUNDS)
    expect(h.pendingTimers()).toBeGreaterThan(0)
  })

  it('debounces enqueue triggers into one pass, and does not pull for it', async () => {
    const h = harness()

    h.runner.trigger('enqueue')
    h.runner.trigger('enqueue')
    h.runner.trigger('enqueue')
    await h.advance(1_000)
    expect(h.push).not.toHaveBeenCalled()

    await h.advance(600)

    expect(h.push).toHaveBeenCalledTimes(1)
    expect(h.pull).not.toHaveBeenCalled()
  })

  it('holds an enqueue while unreachable and runs it at the reachable edge', async () => {
    const h = harness({ reachable: false })

    h.runner.trigger('enqueue')
    await h.advance(5_000)
    expect(h.push).not.toHaveBeenCalled()

    h.state.reachable = true
    h.runner.onReachability(true)
    await flush()

    expect(h.push).toHaveBeenCalledTimes(1)
  })

  it('backs off on a retryable failure with the ladder, and resets after a success', async () => {
    let failures = 2
    const h = harness({ push: async () => { if (failures-- > 0) throw new TypeError('Failed to fetch'); return pushed(0) } })

    h.runner.trigger('session')
    await flush()
    expect(h.status()).toMatchObject({ phase: 'backoff', failure: 'retry', nextAttemptAt: 1_000_000 + 2_000 })

    await h.advance(2_000)
    expect(h.status()).toMatchObject({ phase: 'backoff', nextAttemptAt: 1_002_000 + 5_000 })

    await h.advance(5_000)
    expect(h.status()).toMatchObject({ phase: 'idle', failure: null, nextAttemptAt: null })
    expect(h.recordSuccess).toHaveBeenCalledTimes(1)

    failures = 1
    h.runner.trigger('reachable')
    await flush()
    expect(h.status()).toMatchObject({ phase: 'backoff' })
    expect((h.status().nextAttemptAt as number) - 1_007_000).toBe(2_000)
  })

  it('does not cut a backoff short for an enqueue or a tick, but does for a reachable edge, which resets the ladder', async () => {
    let failing = true
    const h = harness({ push: async () => { if (failing) throw new TypeError('Failed to fetch'); return pushed(0) } })
    h.runner.trigger('session')
    await flush()
    await h.advance(2_000)
    expect(h.push).toHaveBeenCalledTimes(2)
    expect(h.status().nextAttemptAt).toBe(1_002_000 + 5_000)

    h.runner.trigger('enqueue')
    await h.advance(1_600)
    h.runner.trigger('tick')
    await flush()
    expect(h.push).toHaveBeenCalledTimes(2)

    failing = false
    h.runner.onReachability(true)
    await flush()
    expect(h.push).toHaveBeenCalledTimes(3)
    expect(h.status()).toMatchObject({ phase: 'idle', nextAttemptAt: null })
  })

  it('drops the backoff timer when the link goes away and waits for the reachable edge', async () => {
    let failing = true
    const h = harness({ push: async () => { if (failing) throw new TypeError('Failed to fetch'); return pushed(0) } })
    h.runner.trigger('session')
    await flush()
    const timers = h.pendingTimers()

    h.state.reachable = false
    h.runner.onReachability(false)
    await h.advance(600_000)

    expect(h.pendingTimers()).toBeLessThan(timers)
    expect(h.push).toHaveBeenCalledTimes(1)
    expect(h.status()).toMatchObject({ phase: 'backoff', nextAttemptAt: null })

    failing = false
    h.state.reachable = true
    h.runner.onReachability(true)
    await flush()
    expect(h.push).toHaveBeenCalledTimes(2)
    expect(h.status().phase).toBe('idle')
  })

  it('when the backoff timer fires with the link down and no edge seen, waits for the link instead of showing a past retry time', async () => {
    let failing = true
    const h = harness({ push: async () => { if (failing) throw new TypeError('Failed to fetch'); return pushed(0) } })
    h.runner.trigger('session')
    await flush()
    expect(h.status().nextAttemptAt).toBe(1_002_000)

    h.state.reachable = false
    await h.advance(2_000)

    expect(h.push).toHaveBeenCalledTimes(1)
    expect(h.status()).toMatchObject({ phase: 'backoff', nextAttemptAt: null })

    failing = false
    h.state.reachable = true
    h.runner.onReachability(true)
    await flush()
    expect(h.push).toHaveBeenCalledTimes(2)
    expect(h.status().phase).toBe('idle')
  })

  it('on a reauth failure calls onReauth once and ignores every later trigger', async () => {
    const h = harness({ push: async () => { throw new ApiError(401, null) } })

    h.runner.trigger('session')
    await flush()
    h.runner.trigger('reachable')
    h.runner.trigger('session')
    await flush()

    expect(h.onReauth).toHaveBeenCalledTimes(1)
    expect(h.onReauth).toHaveBeenCalledWith('tok')
    expect(h.push).toHaveBeenCalledTimes(1)
    expect(h.status()).toMatchObject({ phase: 'error', failure: 'reauth' })
    expect(h.pendingTimers()).toBe(0)
  })

  it.each([
    ['a missing ability', new ApiError(403, { message: 'forbidden' })],
    ['a malformed batch', new ApiError(422, null)],
    ['a reply it cannot trust', new SyncPushProtocolError('bad')],
  ])('stops on %s with no timer, and only a session, reachable, or visible trigger restarts it', async (_name, error) => {
    let failing = true
    const h = harness({ push: async () => { if (failing) throw error; return pushed(0) } })

    h.runner.trigger('session')
    await flush()
    expect(h.status()).toMatchObject({ phase: 'error', failure: 'defect' })
    expect(h.status().message).toBeTruthy()
    expect(h.pendingTimers()).toBe(0)

    h.runner.trigger('enqueue')
    h.runner.trigger('tick')
    await h.advance(600_000)
    expect(h.push).toHaveBeenCalledTimes(1)

    failing = false
    h.runner.trigger('visible')
    await flush()
    expect(h.push).toHaveBeenCalledTimes(2)
    expect(h.status()).toMatchObject({ phase: 'idle', failure: null })
  })

  it('classifies a malformed pull the same way', async () => {
    const h = harness({ pull: async () => { throw new Error('Invalid GET /sync response') } })

    h.runner.trigger('session')
    await flush()

    expect(h.status()).toMatchObject({ phase: 'error', failure: 'defect', message: 'Invalid GET /sync response' })
    expect(h.recordSuccess).not.toHaveBeenCalled()
  })

  it('writes nothing and clears its timers once disposed, even with a request in flight', async () => {
    let release!: () => void
    const gate = new Promise<void>((resolve) => { release = resolve })
    const h = harness({ push: async () => { await gate; throw new ApiError(401, null) } })
    h.runner.trigger('session')
    await flush()
    h.runner.trigger('enqueue')
    const written = h.statuses.length

    h.runner.dispose()
    release()
    await flush()

    expect(h.statuses).toHaveLength(written)
    expect(h.onReauth).not.toHaveBeenCalled()
    expect(h.recordSuccess).not.toHaveBeenCalled()
    expect(h.pendingTimers()).toBe(0)
    h.runner.trigger('session')
    await flush()
    expect(h.push).toHaveBeenCalledTimes(1)
  })

  it('skips a pass while another tab holds the lock, keeps what was asked, and retries slowly once it is free', async () => {
    let granted = false
    const h = harness({ lock: async (run) => (granted ? { ran: true, value: await run() } : { ran: false }) })

    h.runner.trigger('session')
    await flush()

    expect(h.push).not.toHaveBeenCalled()
    expect(h.recordSuccess).not.toHaveBeenCalled()
    expect(h.status().phase).toBe('idle')
    expect(h.pendingTimers()).toBe(1)

    await h.advance(1_000)
    expect(h.push).not.toHaveBeenCalled()

    granted = true
    await h.advance(5_000)
    expect(h.push).toHaveBeenCalledTimes(1)
    expect(h.pull).toHaveBeenCalledTimes(1)
    expect(h.recordSuccess).toHaveBeenCalledTimes(1)
  })

  it('ticks while the tab is visible and reachable, and not otherwise', async () => {
    const h = harness()
    h.runner.start()

    await h.advance(120_000)
    expect(h.push).toHaveBeenCalledTimes(1)
    expect(h.pull).toHaveBeenCalledTimes(1)

    h.state.visible = false
    await h.advance(240_000)
    expect(h.push).toHaveBeenCalledTimes(1)

    h.state.visible = true
    h.state.reachable = false
    await h.advance(120_000)
    expect(h.push).toHaveBeenCalledTimes(1)

    h.state.reachable = true
    await h.advance(120_000)
    expect(h.push).toHaveBeenCalledTimes(2)
  })
})

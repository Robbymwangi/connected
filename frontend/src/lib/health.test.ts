import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createHealthMonitor,
  HEALTH_URL,
  POLL_INTERVAL_MS,
  probeHealth,
  PROBE_TIMEOUT_MS,
  RETRY_INTERVAL_MS,
} from './health'

const json = (body: unknown, init?: ResponseInit) =>
  new Response(JSON.stringify(body), { status: 200, ...init })

describe('probeHealth', () => {
  it('asks the health route with the cache bypassed', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ ok: true }))
    await probeHealth(fetchMock)
    expect(fetchMock).toHaveBeenCalledWith(HEALTH_URL, expect.objectContaining({ cache: 'no-store' }))
    expect(HEALTH_URL).toBe('/api/health')
  })

  it('is reachable only for a 200 carrying {ok: true}', async () => {
    expect(await probeHealth(vi.fn().mockResolvedValue(json({ ok: true })))).toBe(true)
    expect(await probeHealth(vi.fn().mockResolvedValue(json({ ok: false })))).toBe(false)
    expect(await probeHealth(vi.fn().mockResolvedValue(json({})))).toBe(false)
    expect(await probeHealth(vi.fn().mockResolvedValue(json(null)))).toBe(false)
  })

  it('treats a non-200 as unreachable', async () => {
    expect(await probeHealth(vi.fn().mockResolvedValue(json({ ok: true }, { status: 503 })))).toBe(false)
    expect(await probeHealth(vi.fn().mockResolvedValue(json({ ok: true }, { status: 404 })))).toBe(false)
  })

  it('wants exactly 200, not any success status', async () => {
    expect(await probeHealth(vi.fn().mockResolvedValue(json({ ok: true }, { status: 201 })))).toBe(false)
    expect(await probeHealth(vi.fn().mockResolvedValue(json({ ok: true }, { status: 200 })))).toBe(true)
  })

  it('treats a captive portal (200 with HTML) as unreachable', async () => {
    const portal = new Response('<html>Sign in to Wi-Fi</html>', { status: 200 })
    expect(await probeHealth(vi.fn().mockResolvedValue(portal))).toBe(false)
  })

  it('treats a network error as unreachable', async () => {
    expect(await probeHealth(vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))).toBe(false)
  })

  describe('with a request that never answers', () => {
    beforeEach(() => vi.useFakeTimers())
    afterEach(() => vi.useRealTimers())

    it('gives up after the timeout by aborting the request', async () => {
      const hangs = vi.fn(
        (_url: string, init?: RequestInit) =>
          new Promise<Response>((_resolve, reject) => {
            init?.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
          }),
      )
      const result = probeHealth(hangs as unknown as typeof fetch)
      await vi.advanceTimersByTimeAsync(PROBE_TIMEOUT_MS)
      expect(await result).toBe(false)
    })
  })
})

describe('createHealthMonitor', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  const monitorWith = (results: boolean[], canProbe?: () => boolean) => {
    let i = 0
    const probe = vi.fn(async () => results[Math.min(i++, results.length - 1)])
    return { probe, monitor: createHealthMonitor({ probe, canProbe }) }
  }

  it('assumes reachable until a probe says otherwise', () => {
    const { monitor } = monitorWith([false])
    expect(monitor.getSnapshot()).toBe(true)
  })

  it('probes at once on the first subscriber, and flips and notifies when the probe fails', async () => {
    const { monitor, probe } = monitorWith([false])
    const listener = vi.fn()
    monitor.subscribe(listener)

    await vi.advanceTimersByTimeAsync(0)
    expect(probe).toHaveBeenCalledTimes(1)
    expect(monitor.getSnapshot()).toBe(false)
    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('does not notify while the answer stays the same', async () => {
    const { monitor } = monitorWith([true])
    const listener = vi.fn()
    monitor.subscribe(listener)

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3)
    expect(listener).not.toHaveBeenCalled()
  })

  it('polls on the long interval while reachable and the short one while not', async () => {
    const { monitor, probe } = monitorWith([true, false, false, true])
    monitor.subscribe(() => {})

    await vi.advanceTimersByTimeAsync(0)
    expect(probe).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS - 1)
    expect(probe).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(probe).toHaveBeenCalledTimes(2)
    expect(monitor.getSnapshot()).toBe(false)

    await vi.advanceTimersByTimeAsync(RETRY_INTERVAL_MS)
    expect(probe).toHaveBeenCalledTimes(3)
    await vi.advanceTimersByTimeAsync(RETRY_INTERVAL_MS)
    expect(probe).toHaveBeenCalledTimes(4)
    expect(monitor.getSnapshot()).toBe(true)
  })

  it('shares one poll between any number of subscribers', async () => {
    const { monitor, probe } = monitorWith([true])
    monitor.subscribe(() => {})
    monitor.subscribe(() => {})
    monitor.subscribe(() => {})

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(probe).toHaveBeenCalledTimes(2)
  })

  it('stops polling when the last subscriber leaves, and drops a probe still in flight', async () => {
    let resolveProbe: (value: boolean) => void = () => {}
    const probe = vi.fn(() => new Promise<boolean>((resolve) => (resolveProbe = resolve)))
    const monitor = createHealthMonitor({ probe })
    const listener = vi.fn()

    const first = monitor.subscribe(listener)
    const second = monitor.subscribe(() => {})
    first()
    expect(probe).toHaveBeenCalledTimes(1)
    second()

    resolveProbe(false)
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 5)
    expect(listener).not.toHaveBeenCalled()
    expect(monitor.getSnapshot()).toBe(true)
    expect(probe).toHaveBeenCalledTimes(1)
  })

  it('skips the probe while it cannot run, and resumes on its own', async () => {
    let allowed = false
    const { monitor, probe } = monitorWith([true], () => allowed)
    monitor.subscribe(() => {})

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 2)
    expect(probe).not.toHaveBeenCalled()

    allowed = true
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(probe).toHaveBeenCalledTimes(1)
  })

  it('re-checks immediately on probeNow, and restarts the interval from there', async () => {
    const { monitor, probe } = monitorWith([true])
    monitor.subscribe(() => {})
    await vi.advanceTimersByTimeAsync(0)
    expect(probe).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS - 1000)
    monitor.probeNow()
    await vi.advanceTimersByTimeAsync(0)
    expect(probe).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS - 1)
    expect(probe).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(1)
    expect(probe).toHaveBeenCalledTimes(3)
  })

  it('forgets a good answer when marked unreachable, and needs a fresh success to recover', async () => {
    const { monitor, probe } = monitorWith([true])
    const listener = vi.fn()
    monitor.subscribe(listener)
    await vi.advanceTimersByTimeAsync(0)
    expect(monitor.getSnapshot()).toBe(true)

    monitor.markUnreachable()
    expect(monitor.getSnapshot()).toBe(false)
    expect(listener).toHaveBeenCalledTimes(1)

    monitor.probeNow()
    await vi.advanceTimersByTimeAsync(0)
    expect(probe).toHaveBeenCalledTimes(2)
    expect(monitor.getSnapshot()).toBe(true)
    expect(listener).toHaveBeenCalledTimes(2)
  })

  it('drops a probe still in flight when marked unreachable, so an old success cannot undo it', async () => {
    let resolveProbe: (value: boolean) => void = () => {}
    const probe = vi.fn(() => new Promise<boolean>((resolve) => (resolveProbe = resolve)))
    const monitor = createHealthMonitor({ probe })
    monitor.subscribe(() => {})

    monitor.markUnreachable()
    resolveProbe(true)
    await vi.advanceTimersByTimeAsync(0)
    expect(monitor.getSnapshot()).toBe(false)
  })

  it('marking unreachable is a no-op, with no notification, when already unreachable or idle', async () => {
    const idle = monitorWith([true]).monitor
    idle.markUnreachable()
    expect(idle.getSnapshot()).toBe(true)

    const { monitor } = monitorWith([false])
    const listener = vi.fn()
    monitor.subscribe(listener)
    await vi.advanceTimersByTimeAsync(0)
    expect(listener).toHaveBeenCalledTimes(1)
    monitor.markUnreachable()
    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('attaches once for any number of subscribers and detaches with the last', () => {
    const detach = vi.fn()
    const attach = vi.fn(() => detach)
    const monitor = createHealthMonitor({ probe: async () => true, attach })

    const first = monitor.subscribe(() => {})
    const second = monitor.subscribe(() => {})
    expect(attach).toHaveBeenCalledTimes(1)

    first()
    expect(detach).not.toHaveBeenCalled()
    second()
    expect(detach).toHaveBeenCalledTimes(1)

    monitor.subscribe(() => {})
    expect(attach).toHaveBeenCalledTimes(2)
  })

  it('hands the attach hook working controls', async () => {
    let controls: { probeNow: () => void; markUnreachable: () => void } | undefined
    const probe = vi.fn(async () => true)
    const monitor = createHealthMonitor({
      probe,
      attach: (c) => {
        controls = c
        return () => {}
      },
    })
    monitor.subscribe(() => {})
    await vi.advanceTimersByTimeAsync(0)

    controls?.markUnreachable()
    expect(monitor.getSnapshot()).toBe(false)
    controls?.probeNow()
    await vi.advanceTimersByTimeAsync(0)
    expect(monitor.getSnapshot()).toBe(true)
  })

  it('ignores probeNow when nobody is subscribed', async () => {
    const { monitor, probe } = monitorWith([true])
    monitor.probeNow()
    await vi.advanceTimersByTimeAsync(0)
    expect(probe).not.toHaveBeenCalled()
  })
})

/* The API reachability probe behind useConnectivity (#44, docs/build-plan.md 1.6).
   navigator.onLine is a link-layer signal: it stays true behind a captive portal
   or a dead uplink. The only honest evidence the API is reachable is asking it,
   so this polls GET /api/health. It is a fact about connectivity alone; sync
   state is a separate fact and never inferred from it.

   Framework-free on purpose: the browser hooks (events, timers, fetch) are
   injected, so the whole schedule is unit-tested without a DOM. */

export const HEALTH_URL = '/api/health'
export const PROBE_TIMEOUT_MS = 5_000
/* While the API answers, a light check; while it does not, a faster one, so the
   pill recovers soon after the network returns instead of up to a full interval. */
export const POLL_INTERVAL_MS = 30_000
export const RETRY_INTERVAL_MS = 10_000

/* True only for exactly 200 carrying {ok: true}. A captive portal answers 200 with
   HTML, so the body is checked; any throw, timeout, or other status is
   "unreachable". */
export async function probeHealth(
  fetchImpl: typeof fetch = fetch,
  timeoutMs: number = PROBE_TIMEOUT_MS,
): Promise<boolean> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)
  try {
    const response = await fetchImpl(HEALTH_URL, {
      cache: 'no-store',
      signal: controller.signal,
    })
    if (response.status !== 200) return false
    const body: unknown = await response.json()
    return typeof body === 'object' && body !== null && (body as { ok?: unknown }).ok === true
  } catch {
    return false
  } finally {
    clearTimeout(timer)
  }
}

type MonitorOptions = {
  probe: () => Promise<boolean>
  /* False while a probe would be meaningless or wasteful: the browser reports no
     link, or the tab is hidden. The schedule keeps ticking and resumes by itself. */
  canProbe?: () => boolean
  intervalMs?: number
  retryMs?: number
  /* Wires the monitor to the outside world while it is running (browser events,
     in the app) and returns the cleanup. Lives here, not in each hook instance, so
     one reconnect is one probe however many components use the hook. */
  attach?: (control: { probeNow: () => void; markUnreachable: () => void }) => () => void
}

/* One shared monitor, reference counted: however many components use the
   connectivity hook, there is one poll. Starts on the first subscriber, stops on
   the last. The snapshot is optimistic (reachable) until a probe says otherwise,
   so a device never shows offline on the strength of a probe it has not made. */
export function createHealthMonitor({
  probe,
  canProbe = () => true,
  intervalMs = POLL_INTERVAL_MS,
  retryMs = RETRY_INTERVAL_MS,
  attach,
}: MonitorOptions) {
  const listeners = new Set<() => void>()
  let detach: (() => void) | undefined
  let reachable = true
  let timer: ReturnType<typeof setTimeout> | undefined
  /* A probe in flight when the last subscriber leaves must not write back or
     reschedule; every start bumps this so stale results are dropped. */
  let generation = 0
  let running = false

  const schedule = (id: number) => {
    timer = setTimeout(() => void tick(id), reachable ? intervalMs : retryMs)
  }

  const tick = async (id: number) => {
    if (id !== generation) return
    if (canProbe()) {
      const result = await probe()
      if (id !== generation) return
      if (result !== reachable) {
        reachable = result
        listeners.forEach((listener) => listener())
      }
    }
    if (id === generation) schedule(id)
  }

  /* Re-check now, outside the schedule: the browser just said it is back online or
     the tab became visible, and waiting out the interval would leave the pill
     stale. Restarts the schedule so the next tick is a full delay away. */
  const probeNow = () => {
    if (!running) return
    clearTimeout(timer)
    generation += 1
    void tick(generation)
  }

  /* The browser lost its link, so the last answer no longer means anything: forget
     it, drop any probe still in flight, and require a fresh success before the
     hook can report online again. Without this, a reconnect would briefly read as
     online on the strength of an answer from before the link dropped. */
  const markUnreachable = () => {
    if (!running) return
    clearTimeout(timer)
    generation += 1
    if (reachable) {
      reachable = false
      listeners.forEach((listener) => listener())
    }
    schedule(generation)
  }

  const start = () => {
    running = true
    generation += 1
    detach = attach?.({ probeNow, markUnreachable })
    void tick(generation)
  }

  const stop = () => {
    running = false
    generation += 1
    clearTimeout(timer)
    timer = undefined
    detach?.()
    detach = undefined
  }

  return {
    subscribe(listener: () => void) {
      listeners.add(listener)
      if (!running) start()
      return () => {
        listeners.delete(listener)
        if (listeners.size === 0) stop()
      }
    },
    getSnapshot: () => reachable,
    probeNow,
    markUnreachable,
  }
}

/* The app's single monitor, wired to the real fetch and the real browser: one set
   of event listeners for the whole app, attached while anything is subscribed. */
export const healthMonitor = createHealthMonitor({
  probe: () => probeHealth(),
  canProbe: () => navigator.onLine && document.visibilityState !== 'hidden',
  attach: ({ probeNow, markUnreachable }) => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') probeNow()
    }
    window.addEventListener('online', probeNow)
    window.addEventListener('offline', markUnreachable)
    document.addEventListener('visibilitychange', onVisible)
    /* Already offline when the app starts: the optimistic default must not survive
       into the first reconnect. */
    if (!navigator.onLine) markUnreachable()
    return () => {
      window.removeEventListener('online', probeNow)
      window.removeEventListener('offline', markUnreachable)
      document.removeEventListener('visibilitychange', onVisible)
    }
  },
})

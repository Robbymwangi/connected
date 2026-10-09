import { liveQuery } from 'dexie'
import type { FetchImpl } from '../api/client'
import { connectivityOverride } from './connectivityOverride'
import { healthMonitor } from './health'
import { localDatabaseFor, type LocalDatabase } from './localDatabase'
import { classifySyncFailure, pushSync, type PushFailure, type SyncPushResult } from './syncPush'
import { pullSync } from './syncPull'

/* The sync runner (build-plan 3.4). pushSync and pullSync each do one thing once; this
   decides when, how often, and what to do when it fails. The logic is framework-free
   and takes its clock, connectivity, visibility, and lock as parameters, like
   lib/health.ts, so every path is tested without a browser or real time.

   Sync state is its own fact. Nothing here is inferred from connectivity beyond
   "do not start a request that cannot arrive", and nothing it reports mentions the
   connection. */

export type Phase = 'idle' | 'pushing' | 'pulling' | 'backoff' | 'error'
export type RunReason = 'session' | 'reachable' | 'enqueue' | 'tick' | 'visible'

export type RunnerStatus = {
  phase: Phase
  failure: PushFailure | null
  message: string | null
  /* When the next retry fires, as a clock reading; null when waiting for the link. */
  nextAttemptAt: number | null
}

export const MAX_PUSH_ROUNDS = 20
export const MAX_CYCLES = 3
export const ENQUEUE_DEBOUNCE_MS = 1_500
export const TICK_MS = 120_000
const FOLLOW_UP_MS = 1_000
/* Another tab is syncing this account; look again, but not in a tight loop. */
const LOCK_RETRY_MS = 5_000
/* After a retryable failure: 2 s, then 5, 15, 30, 60, 120, and 300 s from there. */
export const BACKOFF_MS = [2_000, 5_000, 15_000, 30_000, 60_000, 120_000, 300_000]
const JITTER = 0.2

export const syncLastSuccessKey = 'syncLastSuccessAt'

/* Jittered so a classroom of devices that lost the same access point do not all come
   back in the same second. */
export function nextDelay(attempt: number, random: () => number = Math.random): number {
  const base = BACKOFF_MS[Math.min(attempt, BACKOFF_MS.length - 1)]
  return Math.round(base * (1 + (random() * 2 - 1) * JITTER))
}

type PassDeps = {
  push: () => Promise<SyncPushResult>
  pull: () => Promise<unknown>
  onPhase?: (phase: 'pushing' | 'pulling') => void
}

/* One pass: push until nothing is sendable, then pull when there is a reason to. The
   loop ends on what was sent, never on what is held: entries parked behind a conflict
   wait for it, and looping on them would spin. A round that sent entries but settled
   none means another tab answered them, so it stops too. */
export async function runSyncPass(deps: PassDeps, reasons: ReadonlySet<RunReason>): Promise<{ morePush: boolean }> {
  let settledAny = false
  let morePush = false

  for (let round = 0; round < MAX_PUSH_ROUNDS; round++) {
    deps.onPhase?.('pushing')
    const { sent, settled } = await deps.push()
    const answered = settled.accepted + settled.merged + settled.conflict + settled.forbidden + settled.invalid
    if (sent === 0 || answered === 0) break
    if (settled.accepted + settled.merged + settled.conflict > 0) settledAny = true
    if (round === MAX_PUSH_ROUNDS - 1) morePush = true
  }

  /* A debounced enqueue alone does not pull. Anything the server accepted does: the
     log row at the acknowledged version carries what only the server writes, and a
     conflict record arrives by pull. */
  if ([...reasons].some((reason) => reason !== 'enqueue') || settledAny) {
    deps.onPhase?.('pulling')
    const pulled = await deps.pull()
    /* A pull that released edits held behind a resolved conflict has rebased them, which
       queues nothing new for the enqueue watcher to see; so say there is more to send. */
    if (typeof pulled === 'object' && pulled !== null && (pulled as { released?: unknown }).released) morePush = true
  }
  return { morePush }
}

export type Clock = {
  now: () => number
  setTimer: (fn: () => void, ms: number) => unknown
  clearTimer: (id: unknown) => void
}

export type Lock = <T>(run: () => Promise<T>) => Promise<{ ran: true; value: T } | { ran: false }>

type StatusSink = { get: () => RunnerStatus; set: (status: RunnerStatus) => void }

type RunnerDeps = {
  token: string
  push: () => Promise<SyncPushResult>
  pull: () => Promise<unknown>
  onReauth: (token: string) => void
  recordSuccess: () => Promise<void>
  isReachable: () => boolean
  isVisible: () => boolean
  clock: Clock
  status: StatusSink
  random?: () => number
  lock?: Lock
  debounceMs?: number
  tickMs?: number
  /* The wait after the nth consecutive retryable failure; the ladder by default. */
  delay?: (attempt: number) => number
}

const passThrough: Lock = async (run) => ({ ran: true, value: await run() })

/* These end a backoff early: the situation changed, so waiting out the timer would be
   waiting for nothing. An enqueue or a tick is not news, and must not hammer a server
   that just failed. */
const ENDS_BACKOFF: ReadonlySet<RunReason> = new Set(['session', 'reachable', 'visible'])

export function createSyncRunner(deps: RunnerDeps) {
  const { clock } = deps
  const random = deps.random ?? Math.random
  const lock = deps.lock ?? passThrough
  const debounceMs = deps.debounceMs ?? ENQUEUE_DEBOUNCE_MS
  const tickMs = deps.tickMs ?? TICK_MS

  const pending = new Set<RunReason>()
  let running = false
  let disposed = false
  let halted: 'reauth' | 'defect' | null = null
  let inBackoff = false
  let attempt = 0
  let backoffTimer: unknown = null
  let debounceTimer: unknown = null
  let tickTimer: unknown = null
  let followUpTimer: unknown = null

  const stop = (timer: unknown) => {
    if (timer !== null) clock.clearTimer(timer)
    return null
  }

  /* Writes only on a change, and never after disposal, so an unbound runner whose
     request is still in flight cannot touch the status of the one that replaced it. */
  const setStatus = (next: Partial<RunnerStatus>) => {
    if (disposed) return
    const current = deps.status.get()
    const merged = { ...current, ...next }
    if (merged.phase === current.phase && merged.failure === current.failure
      && merged.message === current.message && merged.nextAttemptAt === current.nextAttemptAt) return
    deps.status.set(merged)
  }

  function request(reason: RunReason) {
    if (disposed || halted === 'reauth') return
    pending.add(reason)
    if (running) return
    if (!deps.isReachable()) return
    if (inBackoff && !ENDS_BACKOFF.has(reason)) return
    void run()
  }

  function trigger(reason: RunReason) {
    if (disposed || halted === 'reauth') return
    if (halted === 'defect') {
      if (reason === 'enqueue' || reason === 'tick') return
      halted = null
    }
    if (reason === 'enqueue') {
      debounceTimer = stop(debounceTimer)
      debounceTimer = clock.setTimer(() => {
        debounceTimer = null
        request('enqueue')
      }, debounceMs)
      return
    }
    if (reason === 'session' || reason === 'reachable') attempt = 0
    if (inBackoff && ENDS_BACKOFF.has(reason)) {
      backoffTimer = stop(backoffTimer)
      inBackoff = false
    }
    request(reason)
  }

  function onReachability(reachable: boolean) {
    if (disposed) return
    if (reachable) {
      trigger('reachable')
      return
    }
    /* No point retrying into a dead link; the reachable edge restarts the work, and
       what was asked for is still in pending. Whichever timer was counting down to a
       retry, there is no deadline to show any more. */
    if (deps.status.get().phase === 'backoff') {
      backoffTimer = stop(backoffTimer)
      followUpTimer = stop(followUpTimer)
      setStatus({ nextAttemptAt: null })
    }
  }

  function fail(error: unknown, reasons: ReadonlySet<RunReason>) {
    const kind = classifySyncFailure(error)
    const message = error instanceof Error ? error.message : 'Sync failed'

    if (kind === 'retry') {
      for (const reason of reasons) pending.add(reason)
      const delay = (deps.delay ?? ((nth: number) => nextDelay(nth, random)))(attempt++)
      inBackoff = true
      if (deps.isReachable()) {
        setStatus({ phase: 'backoff', failure: 'retry', message, nextAttemptAt: clock.now() + delay })
        backoffTimer = clock.setTimer(() => {
          backoffTimer = null
          /* The link may have dropped without this runner seeing the edge. Say so,
             rather than show a retry time that has passed, and wait for it. */
          if (!deps.isReachable()) {
            setStatus({ nextAttemptAt: null })
            return
          }
          inBackoff = false
          request('tick')
        }, delay)
      } else {
        setStatus({ phase: 'backoff', failure: 'retry', message, nextAttemptAt: null })
      }
      return
    }

    backoffTimer = stop(backoffTimer)
    debounceTimer = stop(debounceTimer)
    followUpTimer = stop(followUpTimer)
    inBackoff = false
    pending.clear()
    halted = kind === 'reauth' ? 'reauth' : 'defect'
    setStatus({ phase: 'error', failure: kind, message, nextAttemptAt: null })
    if (kind === 'reauth') deps.onReauth(deps.token)
  }

  async function run() {
    running = true
    let reasons: Set<RunReason> = new Set()
    let cycles = 0
    let ranAny = false
    let lockDenied = false

    try {
      do {
        reasons = new Set(pending)
        pending.clear()
        const outcome = await lock(() => runSyncPass({
          push: deps.push,
          pull: deps.pull,
          onPhase: (phase) => setStatus({ phase, failure: null, message: null, nextAttemptAt: null }),
        }, reasons))
        if (disposed) return
        cycles++
        if (!outcome.ran) {
          /* Another tab holds the lock. What was asked for is not lost: the holder
             may have finished before this tab's entry was queued. */
          for (const reason of reasons) pending.add(reason)
          lockDenied = true
          break
        }
        ranAny = true
        attempt = 0
        if (outcome.value.morePush) pending.add('enqueue')
      } while (pending.size > 0 && cycles < MAX_CYCLES)

      inBackoff = false
      if (lockDenied && !ranAny && deps.status.get().phase === 'backoff') {
        /* A retry wake that another tab held the lock against. This tab never learned
           how that pass ended, so the failure it last saw stands, with a deadline for
           the next look, instead of a claim that nothing is wrong. */
        setStatus({ nextAttemptAt: clock.now() + LOCK_RETRY_MS })
      } else {
        if (ranAny) await deps.recordSuccess().catch(() => undefined)
        setStatus(idle)
      }

      /* Triggers that outlasted this wake's cycle budget are not dropped; they get a
         later turn instead of an unbounded loop. */
      if (!disposed && pending.size > 0) {
        followUpTimer = clock.setTimer(() => {
          followUpTimer = null
          /* As for the backoff timer: the link may have dropped without an edge being
             seen, and a deadline that has passed must not stay on show. */
          if (!deps.isReachable() && deps.status.get().phase === 'backoff') {
            setStatus({ nextAttemptAt: null })
            return
          }
          request('tick')
        }, lockDenied ? LOCK_RETRY_MS : FOLLOW_UP_MS)
      }
    } catch (error) {
      if (!disposed) fail(error, reasons)
    } finally {
      running = false
    }
  }

  function scheduleTick() {
    tickTimer = clock.setTimer(() => {
      tickTimer = null
      if (disposed) return
      if (deps.isVisible() && deps.isReachable()) trigger('tick')
      scheduleTick()
    }, tickMs)
  }

  return {
    trigger,
    onReachability,
    start() {
      if (!disposed && tickTimer === null) scheduleTick()
    },
    dispose() {
      disposed = true
      backoffTimer = stop(backoffTimer)
      debounceTimer = stop(debounceTimer)
      tickTimer = stop(tickTimer)
      followUpTimer = stop(followUpTimer)
      pending.clear()
    },
  }
}

/* The runner's phase lives in memory, never in Dexie, so a crash cannot leave
   "pushing" behind. One store per database, which the display reads. */
const idle: RunnerStatus = { phase: 'idle', failure: null, message: null, nextAttemptAt: null }

type StatusStore = StatusSink & {
  subscribe: (listener: () => void) => () => void
  getSnapshot: () => RunnerStatus
  reset: () => void
}

const statusStores = new WeakMap<LocalDatabase, StatusStore>()

export function syncStatusFor(database: LocalDatabase): StatusStore {
  let store = statusStores.get(database)
  if (!store) {
    let current = idle
    const listeners = new Set<() => void>()
    const set = (next: RunnerStatus) => {
      current = next
      for (const listener of listeners) listener()
    }
    store = {
      get: () => current,
      getSnapshot: () => current,
      set,
      reset: () => set(idle),
      subscribe: (listener) => {
        listeners.add(listener)
        return () => { listeners.delete(listener) }
      },
    }
    statusStores.set(database, store)
  }
  return store
}

const realClock: Clock = {
  now: () => Date.now(),
  setTimer: (fn, ms) => setTimeout(fn, ms),
  clearTimer: (id) => clearTimeout(id as ReturnType<typeof setTimeout>),
}

const browserReachable = () => connectivityOverride.get() ?? (navigator.onLine && healthMonitor.getSnapshot())

/* One tab syncs at a time per account. A tab that does not get the lock skips; replay
   makes a stray duplicate harmless, so where Web Locks do not exist it runs anyway. */
function webLock(userId: string): Lock {
  return async (run) => {
    if (!('locks' in navigator)) return { ran: true, value: await run() }
    return navigator.locks.request(`connected-sync:${userId}`, { ifAvailable: true }, async (granted) =>
      granted ? { ran: true as const, value: await run() } : { ran: false as const })
  }
}

export type BindOptions = {
  token: string
  userId: string
  onReauth: (token: string) => void
  database?: LocalDatabase
  fetchImpl?: FetchImpl
}

const bound = new WeakMap<LocalDatabase, () => void>()

/* Starts syncing one verified session and returns the function that stops it. Binding
   a database that already has a runner replaces it, so a re-mount or a changed token
   can never leave two. The caller binds only once the session's identity is verified:
   what is pushed here is pushed as this token's user. */
export function bindSyncRunner(options: BindOptions): () => void {
  const { token, userId, onReauth, fetchImpl = fetch } = options
  const database = options.database ?? localDatabaseFor(userId)
  bound.get(database)?.()

  const status = syncStatusFor(database)
  const runner = createSyncRunner({
    token,
    push: () => pushSync(token, userId, fetchImpl, database),
    pull: () => pullSync(token, userId, fetchImpl, database),
    onReauth,
    recordSuccess: async () => { await database.metadata.put({ key: syncLastSuccessKey, value: new Date().toISOString() }) },
    isReachable: browserReachable,
    isVisible: () => document.visibilityState !== 'hidden',
    clock: realClock,
    lock: webLock(userId),
    status,
  })

  let lastReachable = browserReachable()
  const reachability = () => {
    const now = browserReachable()
    if (now === lastReachable) return
    lastReachable = now
    runner.onReachability(now)
  }
  const visibility = () => {
    if (document.visibilityState === 'visible') runner.trigger('visible')
  }
  window.addEventListener('online', reachability)
  window.addEventListener('offline', reachability)
  document.addEventListener('visibilitychange', visibility)
  /* Subscribing also keeps the shared health probe running for the whole session. */
  const unsubscribeHealth = healthMonitor.subscribe(reachability)
  const unsubscribeOverride = connectivityOverride.subscribe(reachability)

  /* A newly queued entry, by its sequence number. Editing a queued entry again
     coalesces into it and adds none, which is right: the debounce is already pending. */
  let known = new Set<number>()
  let primed = false
  const watching = liveQuery(() => database.outbox.where('state').equals('queued').primaryKeys()).subscribe({
    next: (keys) => {
      const fresh = (keys as number[]).filter((key) => !known.has(key))
      known = new Set(keys as number[])
      if (primed && fresh.length > 0) runner.trigger('enqueue')
      primed = true
    },
    error: () => undefined,
  })

  runner.start()
  runner.trigger('session')

  const unbind = () => {
    runner.dispose()
    watching.unsubscribe()
    unsubscribeHealth()
    unsubscribeOverride()
    window.removeEventListener('online', reachability)
    window.removeEventListener('offline', reachability)
    document.removeEventListener('visibilitychange', visibility)
    if (bound.get(database) === unbind) bound.delete(database)
    status.reset()
  }
  bound.set(database, unbind)
  return unbind
}

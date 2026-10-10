import type { LocalDatabase } from './localDatabase'
import type { SyncNotice } from './syncPush'
import { syncLastSuccessKey, type Phase, type RunnerStatus } from './syncRunner'
import { syncNoticesKey } from './syncPush'
import type { PushFailure } from './syncPush'
import { formatRelative } from './time'

/* What the screens show about syncing. It is read from the outbox and the runner, and
   says nothing about the connection: connectivity is a separate fact, shown by its own
   pill, and neither is inferred from the other (AGENTS.md). */

export type SyncRows = {
  queued: number
  sent: number
  failed: number
  notices: SyncNotice[]
  lastSuccessAt: string | null
}

/* The body of the Dexie live query: indexed counts by state, so it stays cheap however
   long the outbox is, and the two metadata values. */
export function readSyncRows(database: LocalDatabase): Promise<SyncRows> {
  return database.transaction('r', [database.outbox, database.metadata], async () => {
    const count = (state: string) => database.outbox.where('state').equals(state).count()
    const [queued, sent, failed] = await Promise.all([count('queued'), count('sent'), count('failed')])
    const notices = (await database.metadata.get(syncNoticesKey))?.value
    const lastSuccessAt = (await database.metadata.get(syncLastSuccessKey))?.value
    return {
      queued,
      sent,
      failed,
      notices: Array.isArray(notices) ? notices as SyncNotice[] : [],
      lastSuccessAt: typeof lastSuccessAt === 'string' ? lastSuccessAt : null,
    }
  })
}

export type SyncState = {
  status: 'loading' | 'ready'
  /* Changes the server has not settled: queued, plus sent and awaiting an answer. */
  pending: number
  conflict: number
  failed: number
  notices: SyncNotice[]
  lastSyncedAt: Date | null
  phase: Phase
  failure: PushFailure | null
  nextAttemptAt: Date | null
}

/* `conflict` is how many conflicts need this person, worked out by the store from the
   conflict rows and the policy (groupConflicts); the outbox does not know who may act on
   what. It is passed in so the top bar, the dashboard, and the Sync screen count the same
   thing. */
export function summarizeSync(rows: SyncRows | null, status: RunnerStatus, conflict = 0): SyncState {
  const synced = rows?.lastSuccessAt ? new Date(rows.lastSuccessAt) : null
  return {
    status: rows ? 'ready' : 'loading',
    pending: rows ? rows.queued + rows.sent : 0,
    conflict: rows ? conflict : 0,
    failed: rows?.failed ?? 0,
    notices: rows?.notices ?? [],
    lastSyncedAt: synced && !Number.isNaN(synced.getTime()) ? synced : null,
    phase: status.phase,
    failure: status.failure,
    nextAttemptAt: status.nextAttemptAt === null ? null : new Date(status.nextAttemptAt),
  }
}

export type SyncCategory = 'loading' | 'syncing' | 'waiting' | 'attention' | 'synced' | 'never' | 'stopped'

export const plural = (count: number) => `${count} ${count === 1 ? 'change' : 'changes'}`

/* One line of text and one announcement for a screen reader. The announcement leaves
   the relative time out, so a live region does not speak every minute as it ages. The
   order is what a person most needs first: a stop, then work in hand, then anything that
   needs them, then what waits, then how fresh everything is. */
export function describeSync(state: SyncState, now: Date): { category: SyncCategory; text: string; announcement: string } {
  if (state.status === 'loading') return { category: 'loading', text: '', announcement: '' }

  if (state.phase === 'error') return { category: 'stopped', text: 'Sync stopped', announcement: 'Sync stopped' }

  const working = state.phase === 'pushing' || state.phase === 'pulling'
  if (working && state.pending > 0) {
    const text = `Syncing ${plural(state.pending)}`
    return { category: 'syncing', text, announcement: text }
  }

  const review = state.conflict + state.failed
  if (review > 0) {
    const text = `${plural(review)} ${review === 1 ? 'needs' : 'need'} review`
    return { category: 'attention', text, announcement: text }
  }

  if (state.pending > 0) {
    const text = `${plural(state.pending)} waiting`
    return { category: 'waiting', text, announcement: text }
  }

  if (state.lastSyncedAt) {
    return { category: 'synced', text: `Synced ${formatRelative(state.lastSyncedAt, now)}`, announcement: 'All changes synced' }
  }
  return { category: 'never', text: 'Not synced yet', announcement: 'Not synced yet' }
}

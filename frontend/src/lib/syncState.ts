import type { LocalDatabase } from './localDatabase'
import type { OutboxEntry } from './outbox'
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
  failedEntries: OutboxEntry[]
  notices: SyncNotice[]
  lastSuccessAt: string | null
}

/* The body of the Dexie live query: indexed counts by state, so it stays cheap however
   long the outbox is, and the two metadata values. */
export function readSyncRows(database: LocalDatabase): Promise<SyncRows> {
  return database.transaction('r', [database.outbox, database.metadata], async () => {
    const count = (state: string) => database.outbox.where('state').equals(state).count()
    const [queued, sent, failedEntries] = await Promise.all([
      count('queued'),
      count('sent'),
      database.outbox.where('state').equals('failed').sortBy('seq'),
    ])
    const notices = (await database.metadata.get(syncNoticesKey))?.value
    const lastSuccessAt = (await database.metadata.get(syncLastSuccessKey))?.value
    return {
      queued,
      sent,
      failed: failedEntries.length,
      failedEntries,
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
  failedEntries: OutboxEntry[]
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
    failedEntries: rows?.failedEntries ?? [],
    notices: rows?.notices ?? [],
    lastSyncedAt: synced && !Number.isNaN(synced.getTime()) ? synced : null,
    phase: status.phase,
    failure: status.failure,
    nextAttemptAt: status.nextAttemptAt === null ? null : new Date(status.nextAttemptAt),
  }
}

export function dismissFailedEntry(database: LocalDatabase, seq: number): Promise<boolean> {
  return database.transaction('rw', database.outbox, async () => {
    const entry = await database.outbox.get(seq)
    if (!entry || entry.state !== 'failed') return false
    await database.outbox.delete(seq)
    return true
  })
}

/* What the device knows about an assessment, to name it in a failed change. */
export type NamedAssessment = { id: string; subject: string; name: string; stream: string; term: string }

const NOT_RETRIED = 'This request was not applied and will not be retried. Use the reason above to decide what to do, or dismiss this failed attempt.'

/* A failed change in a teacher's words: what was being done, to what, and why the server said
   no. An entry carries only the fields it changed (a finalize is just {status}), so the record it
   was about is looked up among the assessments this device holds. The server's own reason is kept
   wherever there is no plainer way to say it. */
export function describeFailedEntry(entry: OutboxEntry, assessments: readonly NamedAssessment[] = []): { title: string; reason: string; action: string } {
  const assessmentId = entry.table === 'assessments' ? entry.recordId
    : typeof entry.fields.assessmentId === 'string' ? entry.fields.assessmentId : null
  const assessment = assessments.find((a) => a.id === assessmentId)
  const label = assessment ? `${assessment.subject} ${assessment.name} (${assessment.stream}, ${assessment.term})`
    : typeof entry.fields.name === 'string' ? entry.fields.name : null
  const finalizing = entry.table === 'assessments' && entry.fields.status === 'finalized'

  const title = entry.table === 'assessments'
    ? `${finalizing ? 'Finalizing' : entry.baseVersion === 0 ? 'Creating' : 'Changing'} ${label ?? 'an assessment'}`
    : entry.table === 'marks' ? `A mark${label ? ` in ${label}` : ''}`
      : entry.table === 'comments' ? 'A report-card remark'
        : `${entry.table.replaceAll('_', ' ')} change`

  if (entry.table === 'assessments' && entry.reason === 'term must be 1, 2, or 3') {
    const term = typeof entry.fields.term === 'string' ? ` (${entry.fields.term})` : ''
    return {
      title,
      reason: `Your selected term${term} was valid, but this older assessment request sent it as text instead of a number.`,
      action: 'Dismiss this failed request and recreate the assessment. New assessments send the term in the correct format.',
    }
  }

  return { title, reason: plainReason(entry, assessment, finalizing), action: NOT_RETRIED }
}

function plainReason(entry: OutboxEntry, assessment: NamedAssessment | undefined, finalizing: boolean): string {
  if (entry.reason === 'forbidden') {
    if (entry.table === 'assessments') {
      const who = assessment ? `a teacher of ${assessment.subject} in ${assessment.stream}` : 'a teacher of its class and subject'
      return `Only the teacher who created it, or ${who}, can ${finalizing ? 'finalize' : 'change'} it.`
    }
    if (entry.table === 'comments') return "Only the stream's class teacher or the Director of Studies can act on its remarks."
    return 'You are not allowed to make this change.'
  }
  if (entry.table === 'marks' && entry.reason === 'assessmentId does not resolve') {
    return 'The assessment this mark belongs to was refused or removed, so the mark could not be saved.'
  }
  return entry.reason ?? 'The server refused this change.'
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
    const conflicts = `${state.conflict} conflict${state.conflict === 1 ? '' : 's'}`
    const failed = `${state.failed} failed ${state.failed === 1 ? 'change' : 'changes'}`
    const text = state.conflict > 0 && state.failed > 0
      ? `${conflicts} and ${failed} need review`
      : state.failed > 0
        ? `${failed} ${state.failed === 1 ? 'needs' : 'need'} review`
        : `${conflicts} ${state.conflict === 1 ? 'needs' : 'need'} review`
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

import type { SyncState } from '../fixtures/assessments'

const DOT_CLASSES: Record<SyncState, string> = {
  synced: 'bg-success',
  pending: 'animate-pulse bg-warning',
  conflict: 'animate-pulse bg-danger',
}

/* A record's sync state. Colour and label together, never colour alone. */
export function SyncDot({ state }: { state: SyncState }) {
  return (
    <span
      role="img"
      aria-label={`Sync: ${state}`}
      title={state}
      className={`inline-block size-2 shrink-0 rounded-full ${DOT_CLASSES[state]}`}
    />
  )
}

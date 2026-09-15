import { Cloud, CloudOff } from 'lucide-react'
import { StatusPill } from '../components/StatusPill'
import { formatRelative } from '../lib/time'

type SyncStatusIndicatorProps = {
  isOnline: boolean
  pendingCount: number
  lastSyncedAt: Date
  /* Present only in development builds; see useConnectivity. */
  onToggleOverride?: () => void
}

/* Two facts, shown separately. The pill reports connectivity and nothing else. The
   line beneath reports sync state: a pending count when the outbox holds mutations,
   otherwise when it was last drained. A device can be online with queued work, and
   a green pill on its own would misrepresent that, so the pending line is set in the
   warning colour and weight to take precedence. */
export function SyncStatusIndicator({
  isOnline,
  pendingCount,
  lastSyncedAt,
  onToggleOverride,
}: SyncStatusIndicatorProps) {
  const pill = (
    <StatusPill
      tone={isOnline ? 'success' : 'neutral'}
      icon={isOnline ? <Cloud className="size-4" /> : <CloudOff className="size-4" />}
    >
      <span className="hidden sm:inline">{isOnline ? 'Online' : 'Offline'}</span>
    </StatusPill>
  )

  return (
    <div className="flex flex-col items-center gap-0.5">
      {onToggleOverride ? (
        <button
          type="button"
          onClick={onToggleOverride}
          title="Development only: toggle connectivity"
          className="rounded-full"
        >
          {pill}
        </button>
      ) : (
        pill
      )}
      {pendingCount > 0 ? (
        <span className="text-[11px] font-semibold text-warning tabular">
          {pendingCount} pending
        </span>
      ) : (
        <span className="text-[11px] font-medium text-muted-foreground">
          Synced {formatRelative(lastSyncedAt)}
        </span>
      )}
    </div>
  )
}

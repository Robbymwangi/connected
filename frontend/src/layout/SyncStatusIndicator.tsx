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
   line beneath reports sync state: when the outbox was last drained, always, because
   the gap between that time and now is how a user estimates drift from the server;
   and a pending count in front of it when the outbox holds mutations. A device can be
   online with queued work, and a green pill on its own would misrepresent that, so
   the count is set in the warning colour and weight to take precedence. */
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
      <span className="text-[11px] font-medium text-muted-foreground">
        {pendingCount > 0 && (
          <span className="font-semibold text-warning tabular">{pendingCount} pending, </span>
        )}
        synced {formatRelative(lastSyncedAt)}
      </span>
    </div>
  )
}

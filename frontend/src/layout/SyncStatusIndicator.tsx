import { Cloud, CloudOff } from 'lucide-react'
import { StatusPill } from '../components/StatusPill'
import { formatRelative } from '../lib/time'
import { useNow } from '../lib/useNow'

type SyncStatusIndicatorProps = {
  isOnline: boolean
  lastSyncedAt: Date
  /* Present only in development builds; see useConnectivity. */
  onToggleOverride?: () => void
}

/* Two facts, shown separately. The pill reports connectivity and nothing else. The
   line beneath reports when the outbox was last drained, and only while offline:
   that gap is how a user estimates drift from the server, and drift is only a
   question when there is no link. Online, the outbox drains as soon as it can. The
   pending count lives on the dashboard's My Progress card, not here. */
export function SyncStatusIndicator({ isOnline, lastSyncedAt, onToggleOverride }: SyncStatusIndicatorProps) {
  const now = useNow()

  const pill = (
    <StatusPill
      tone={isOnline ? 'success' : 'neutral'}
      icon={isOnline ? <Cloud className="size-4" /> : <CloudOff className="size-4" />}
    >
      <span className="hidden sm:inline">{isOnline ? 'Online' : 'Offline'}</span>
    </StatusPill>
  )

  return (
    <div className="flex flex-col items-center gap-1">
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
      {!isOnline && (
        <span className="text-[11px] leading-none font-medium whitespace-nowrap text-muted-foreground">
          Synced {formatRelative(lastSyncedAt, now)}
        </span>
      )}
    </div>
  )
}

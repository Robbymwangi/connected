import { CircleCheck, Clock, Cloud, CloudOff, RefreshCw, TriangleAlert } from 'lucide-react'
import type { ReactNode } from 'react'
import { StatusPill } from '../components/StatusPill'
import { describeSync, type SyncCategory, type SyncState } from '../lib/syncState'
import { useNow } from '../lib/useNow'

type SyncStatusIndicatorProps = {
  isOnline: boolean
  sync: SyncState
  /* Present only in development builds; see useConnectivity. */
  onToggleOverride?: () => void
}

/* Each category has its own icon and its own words, so the state never rests on colour
   alone. Colour only reinforces the two that need a person. */
const LINE: Record<Exclude<SyncCategory, 'loading'>, { icon: ReactNode; tone: string }> = {
  syncing: { icon: <RefreshCw className="size-3 motion-safe:animate-spin" aria-hidden="true" />, tone: 'text-muted-foreground' },
  waiting: { icon: <Clock className="size-3" aria-hidden="true" />, tone: 'text-muted-foreground' },
  synced: { icon: <CircleCheck className="size-3" aria-hidden="true" />, tone: 'text-muted-foreground' },
  never: { icon: <Clock className="size-3" aria-hidden="true" />, tone: 'text-muted-foreground' },
  attention: { icon: <TriangleAlert className="size-3" aria-hidden="true" />, tone: 'text-warning' },
  stopped: { icon: <TriangleAlert className="size-3" aria-hidden="true" />, tone: 'text-danger' },
}

/* Two facts, shown separately and never derived from each other. The pill reports
   connectivity and nothing else. The line beneath reports the outbox: whether changes
   are being sent, waiting, need a person, or have all been sent, and when. It is always
   shown: a teacher who is online still wants to know that what they entered has
   arrived, and one who is offline wants to know what is waiting. Its words never
   mention the connection. The My Progress card on the dashboard shows the same count. */
export function SyncStatusIndicator({ isOnline, sync, onToggleOverride }: SyncStatusIndicatorProps) {
  const now = useNow()
  const { category, text, announcement } = describeSync(sync, now)

  const pill = (
    <StatusPill
      tone={isOnline ? 'success' : 'neutral'}
      icon={isOnline ? <Cloud className="size-4" /> : <CloudOff className="size-4" />}
      aria-label={`Connection: ${isOnline ? 'Online' : 'Offline'}`}
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
      {category !== 'loading' && (
        <span
          data-testid="sync-line"
          data-sync-category={category}
          className={`inline-flex items-center gap-1 text-[11px] leading-none font-medium whitespace-nowrap ${LINE[category].tone}`}
        >
          {LINE[category].icon}
          {text}
        </span>
      )}
      {/* Announces a change of category, not the passing minutes. */}
      <span role="status" aria-live="polite" className="sr-only">
        {announcement}
      </span>
    </div>
  )
}

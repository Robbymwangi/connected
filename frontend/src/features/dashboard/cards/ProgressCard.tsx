import { Card } from '../../../components/Card'
import { KpiTile } from '../../../components/KpiTile'
import { progress } from '../../../fixtures/dashboard'
import { useCurrentUser } from '../../../app/AuthContext'
import { formatRelative } from '../../../lib/time'
import { useSyncState } from '../../../lib/useSyncState'

type ProgressCardProps = {
  /* Conflicts this person can act on; the same count as the top bar and the banner. */
  needsYou: number
  onExpand: () => void
}

export function ProgressCard({ needsYou, onExpand }: ProgressCardProps) {
  const sync = useSyncState(useCurrentUser().id, needsYou)

  return (
    <Card title="My Progress" onExpand={onExpand} expandLabel="Open assessments">
      <div className="flex flex-col gap-4 px-5 pt-4 pb-5">
        <div>
          <p className="font-display text-3xl leading-none font-bold text-primary tabular">
            {progress.studentsEnteredThisWeek}
          </p>
          <p className="mt-1 text-sm text-muted-foreground">Students entered this week</p>
        </div>
        <div className="flex gap-2.5">
          <KpiTile label="Pending Sync" value={sync.status === 'ready' ? sync.pending : '-'} />
          <KpiTile label="Needs Review" value={sync.status === 'ready' ? sync.conflict + sync.failed : '-'} />
          <KpiTile label="Entered Today" value={progress.enteredToday} />
        </div>
        <p className="text-xs text-muted-foreground">
          {sync.lastSyncedAt ? (
            <>
              Last synced{' '}
              <span className="font-semibold text-foreground">{formatRelative(sync.lastSyncedAt)}</span>
            </>
          ) : (
            'Not synced yet'
          )}
        </p>
      </div>
    </Card>
  )
}

import type { ActiveConflict } from '../../fixtures/conflicts'
import { currentUser } from '../../fixtures/user'
import type { NavId } from '../../layout/navigation'
import { formatLongDate, greetingFor } from '../../lib/time'
import { AttentionBanner } from './AttentionBanner'
import { ClassesCard } from './cards/ClassesCard'
import { ContinueWorkingCard } from './cards/ContinueWorkingCard'
import { PendingAssessmentsCard } from './cards/PendingAssessmentsCard'
import { ProgressCard } from './cards/ProgressCard'
import { RecentCard } from './cards/RecentCard'

type DashboardProps = {
  conflicts: ActiveConflict[]
  onNavigate: (id: NavId) => void
  onCreateAssessment: () => void
  onViewConflicts: (highlight?: string) => void
}

/* Static cards. Each card's only way out is to the section that owns its data;
   interactive analytics live in Reports. */
export function Dashboard({ conflicts, onNavigate, onCreateAssessment, onViewConflicts }: DashboardProps) {
  const now = new Date()

  return (
    <>
      <AttentionBanner conflicts={conflicts} onView={onViewConflicts} />
      <div className="px-5 pt-6 pb-10 lg:px-8">
        <div className="mb-4">
          <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">
            {greetingFor(now)}, {currentUser.firstName}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">{formatLongDate(now)}</p>
        </div>

        <div className="mb-4">
          <ContinueWorkingCard onContinue={() => onNavigate('assessments')} />
        </div>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <ProgressCard onExpand={() => onNavigate('assessments')} />
          <ClassesCard onExpand={() => onNavigate('reports')} />
          <RecentCard
            onExpand={() => onNavigate('classes')}
            onOpen={(item) => onNavigate(item.to)}
          />
          <PendingAssessmentsCard
            onExpand={() => onNavigate('assessments')}
            onCreate={onCreateAssessment}
          />
        </div>
      </div>
    </>
  )
}

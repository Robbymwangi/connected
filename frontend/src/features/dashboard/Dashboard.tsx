import { useCurrentUser } from '../../app/AuthContext'
import type { Assessment } from '../../fixtures/assessments'
import type { SchoolClass } from '../../fixtures/classes'
import type { ActiveConflict } from '../../fixtures/conflicts'
import type { NavId } from '../../layout/navigation'
import type { RecentAccess } from '../../lib/recentAccess'
import { formatLongDate, greetingFor } from '../../lib/time'
import { AttentionBanner } from './AttentionBanner'
import { ClassesCard } from './cards/ClassesCard'
import { ContinueWorkingCard } from './cards/ContinueWorkingCard'
import { PendingAssessmentsCard } from './cards/PendingAssessmentsCard'
import { ProgressCard } from './cards/ProgressCard'
import { RecentCard } from './cards/RecentCard'

type DashboardProps = {
  conflicts: ActiveConflict[]
  assessments: Assessment[]
  classes: SchoolClass[]
  year: number
  lastEditedAssessment: Assessment | null
  lastEditedLoading: boolean
  onNavigate: (id: NavId) => void
  onContinueAssessment: () => void
  recentAccesses: RecentAccess[]
  onOpenRecent: (item: RecentAccess) => void
  onOpenAssessment: (assessmentId: string) => void
  onCreateAssessment: () => void
  onViewConflicts: (highlight?: string) => void
}

/* Operational dashboard summaries stay local and offline-capable; interactive
  analytics live in Reports. */
export function Dashboard({ conflicts, assessments, classes, year, lastEditedAssessment, lastEditedLoading, onNavigate, onContinueAssessment, recentAccesses, onOpenRecent, onOpenAssessment, onCreateAssessment, onViewConflicts }: DashboardProps) {
  const currentUser = useCurrentUser()
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

        {!lastEditedLoading && lastEditedAssessment && (
          <div className="mb-4">
            <ContinueWorkingCard assessment={lastEditedAssessment} onContinue={onContinueAssessment} />
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <ProgressCard assessments={assessments} year={year} onExpand={() => onNavigate('assessments')} />
          <ClassesCard classes={classes} assessments={assessments} year={year} onExpand={() => onNavigate('reports')} />
          <RecentCard
            items={recentAccesses}
            onExpand={() => onNavigate('classes')}
            onOpen={onOpenRecent}
          />
          <PendingAssessmentsCard
            assessments={assessments}
            onExpand={() => onNavigate('assessments')}
            onCreate={onCreateAssessment}
            onOpenAssessment={onOpenAssessment}
          />
        </div>
      </div>
    </>
  )
}

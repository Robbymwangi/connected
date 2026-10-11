import { Card } from '../../../components/Card'
import type { Assessment } from '../../../fixtures/assessments'
import { markingProgress } from '../../../lib/dashboardData'

type ProgressCardProps = {
  assessments: Assessment[]
  year: number
  onExpand: () => void
}

export function ProgressCard({ assessments, year, onExpand }: ProgressCardProps) {
  const progress = markingProgress(assessments, year)
  if (progress.inProgressAssessments === 0) return null

  return (
    <Card title="Marking Progress" onExpand={onExpand} expandLabel="Open assessments">
      <div className="px-5 pt-4 pb-5">
        <p className="font-display text-3xl leading-none font-bold text-primary tabular">
          {progress.marksRemaining}
        </p>
        <p className="mt-1 text-sm text-muted-foreground">Marks remaining</p>
        <p className="mt-3 text-xs text-muted-foreground">
          Across {progress.inProgressAssessments} active {progress.inProgressAssessments === 1 ? 'assessment' : 'assessments'} this year
        </p>
      </div>
    </Card>
  )
}

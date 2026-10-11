import { Check, Plus } from 'lucide-react'
import { Card } from '../../../components/Card'
import { ChevronRow } from '../../../components/ChevronRow'
import type { Assessment } from '../../../fixtures/assessments'
import { pendingAssessmentsForDashboard } from '../../../lib/dashboardData'

type PendingAssessmentsCardProps = {
  assessments: Assessment[]
  onExpand: () => void
  onCreate: () => void
  onOpenAssessment: (assessmentId: string) => void
}

export function PendingAssessmentsCard({ assessments, onExpand, onCreate, onOpenAssessment }: PendingAssessmentsCardProps) {
  const pending = pendingAssessmentsForDashboard(assessments).slice(0, 4)
  return (
    <Card
      title="Pending Assessments"
      onExpand={onExpand}
      expandLabel="Open assessments"
      action={
        <button
          type="button"
          onClick={onCreate}
          className="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90"
        >
          <Plus className="size-3.5" /> New
        </button>
      }
    >
      {pending.length > 0 ? (
        pending.map((assessment) => (
          <ChevronRow
            key={assessment.id}
            primary={`${assessment.subject}: ${assessment.name}`}
            secondary={`Stream ${assessment.stream} · ${assessment.term} · ${assessment.status === 'complete' ? 'Ready to finalize' : assessment.status === 'in-progress' ? 'In progress' : 'Scheduled'}`}
            onClick={() => onOpenAssessment(assessment.id)}
          />
        ))
      ) : (
        <div className="flex h-full flex-col items-center justify-center gap-4 py-10">
          <div className="flex size-14 items-center justify-center rounded-full border border-success/20 bg-success/10 text-success">
            <Check className="size-6" />
          </div>
          <div className="text-center">
            <p className="text-base font-semibold text-foreground">All cleared up</p>
            <p className="mt-1 text-sm text-muted-foreground">No pending assessments</p>
          </div>
        </div>
      )}
    </Card>
  )
}

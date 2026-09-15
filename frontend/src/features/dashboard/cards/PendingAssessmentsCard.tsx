import { Check, Plus } from 'lucide-react'
import { Card } from '../../../components/Card'
import { ChevronRow } from '../../../components/ChevronRow'
import { pendingAssessments } from '../../../fixtures/dashboard'

type PendingAssessmentsCardProps = {
  onExpand: () => void
}

export function PendingAssessmentsCard({ onExpand }: PendingAssessmentsCardProps) {
  return (
    <Card
      title="Pending Assessments"
      onExpand={onExpand}
      expandLabel="Open assessments"
      action={
        /* Designed scope with nothing behind it yet, like Log Out. */
        <button
          type="button"
          className="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90"
        >
          <Plus className="size-3.5" /> New
        </button>
      }
    >
      {pendingAssessments.length > 0 ? (
        pendingAssessments.map((item) => (
          <ChevronRow key={item.primary} primary={item.primary} secondary={item.secondary} />
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

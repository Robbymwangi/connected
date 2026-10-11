import { Trash2 } from 'lucide-react'
import { CompletionBar } from '../../components/CompletionBar'
import { SyncDot } from '../../components/SyncDot'
import type { Assessment } from '../../fixtures/assessments'
import { STATUS_META } from './statusMeta'

type AssessmentRowProps = {
  assessment: Assessment
  /* Compact rows omit stream and term because the surrounding view already says. */
  compact?: boolean
  onOpenGrid: () => void
  onOpenReport: () => void
  onDelete?: () => void
}

/* One assessment in a list, with the single action its status calls for. */
export function AssessmentRow({ assessment: a, compact = false, onOpenGrid, onOpenReport, onDelete }: AssessmentRowProps) {
  const needsAction = a.status === 'complete'

  return (
    <div
      className={`flex items-center gap-4 rounded-2xl border bg-card px-5 py-4 shadow-sm transition-all ${
        needsAction ? 'border-warning/35 bg-warning/[0.02]' : 'border-border'
      }`}
    >
      <SyncDot state={a.sync} />
      <div className="min-w-0 flex-1">
        {compact ? (
          <p className="text-sm font-bold text-foreground">
            {a.subject}: {a.name}
          </p>
        ) : (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-bold text-foreground">{a.subject}</span>
            <span className="text-sm text-muted-foreground/40">·</span>
            <span className="text-sm font-medium text-muted-foreground">Stream {a.stream}</span>
            <span className="text-sm text-muted-foreground/40">·</span>
            <span className="text-sm text-foreground/80">{a.name}</span>
            <span className="rounded-full bg-muted/60 px-2 py-0.5 text-xs text-muted-foreground">{a.term}</span>
          </div>
        )}
        <div className="mt-1.5 flex items-center gap-3">
          <div className={compact ? 'w-36' : 'w-40'}>
            <CompletionBar entered={a.entered} total={a.total} />
          </div>
          <span className="text-xs text-muted-foreground">{STATUS_META[a.status].label}</span>
        </div>
      </div>
      <RowAction assessment={a} onOpenGrid={onOpenGrid} onOpenReport={onOpenReport} onDelete={onDelete} />
    </div>
  )
}

function RowAction({ assessment: a, onOpenGrid, onOpenReport, onDelete }: Omit<AssessmentRowProps, 'compact'>) {
  const base = 'shrink-0 rounded-xl px-3 py-1.5 text-xs font-bold transition-colors'
  let action

  if (a.status === 'reports-generated') {
    action = (
      <button
        type="button"
        onClick={onOpenReport}
        className={`${base} border border-success/25 bg-success/10 text-success hover:bg-success/20`}
      >
        View report
      </button>
    )
  }
  else if (a.status === 'complete') {
    action = (
      <button
        type="button"
        onClick={onOpenGrid}
        className={`${base} bg-warning text-warning-foreground hover:opacity-90`}
      >
        Finalize
      </button>
    )
  } else {
    action = (
      <button
        type="button"
        onClick={onOpenGrid}
        className={`${base} border border-primary/25 bg-primary/10 text-primary hover:bg-primary/20`}
      >
        {a.status === 'in-progress' ? 'Continue' : 'Open'}
      </button>
    )
  }

  return (
    <div className="flex shrink-0 items-center gap-2">
      {action}
      {a.status === 'scheduled' && a.entered === 0 && onDelete && (
        <button
          type="button"
          onClick={onDelete}
          aria-label={`Delete ${a.subject} ${a.name} for Stream ${a.stream}`}
          title="Delete scheduled assessment"
          className="rounded-lg p-2 text-muted-foreground transition-colors hover:bg-danger/10 hover:text-danger"
        >
          <Trash2 className="size-4" aria-hidden="true" />
        </button>
      )}
    </div>
  )
}

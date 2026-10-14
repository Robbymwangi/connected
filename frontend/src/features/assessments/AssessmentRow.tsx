import { Trash2 } from 'lucide-react'
import { useCurrentUser } from '../../app/AuthContext'
import { CompletionBar } from '../../components/CompletionBar'
import { SyncDot } from '../../components/SyncDot'
import type { Assessment } from '../../fixtures/assessments'
import type { SchoolDirectory } from '../../lib/schoolDirectory'
import { mayUnlock } from '../../lib/termComments'
import { STATUS_META } from './statusMeta'

type AssessmentRowProps = {
  assessment: Assessment
  /* For what this user may do with the assessment: finalize it, or reach the grid to unlock it.
     Null while the directory loads; finalize is then offered and unlock is not, and the server
     decides either way. */
  school: SchoolDirectory | null
  /* Compact rows omit the stream, which the surrounding view already says, but keep the term. */
  compact?: boolean
  onOpenGrid: () => void
  onOpenReport: () => void
  onDelete?: () => void
}

/* One assessment in a list, with the single action its status calls for. */
export function AssessmentRow({ assessment: a, school, compact = false, onOpenGrid, onOpenReport, onDelete }: AssessmentRowProps) {
  const user = useCurrentUser()
  const subjectId = Object.entries(school?.subjectNameById ?? {}).find(([, name]) => name === a.subject)?.[0]
  const allowed = {
    finalize: school ? school.answerableFor(user.id, a.id) : true,
    unlock: a.status === 'finalized' && mayUnlock(user, subjectId),
  }
  const needsAction = a.status === 'complete' && allowed.finalize

  return (
    <div
      className={`flex items-center gap-4 rounded-2xl border bg-card px-5 py-4 shadow-sm transition-all ${
        needsAction ? 'border-warning/35 bg-warning/[0.02]' : 'border-border'
      }`}
    >
      <SyncDot state={a.sync} />
      <div className="min-w-0 flex-1">
        {compact ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-bold text-foreground">{a.subject}: {a.name}</span>
            <span className="rounded-full bg-muted/60 px-2 py-0.5 text-xs text-muted-foreground">{a.term}, {a.year}</span>
          </div>
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
      <RowAction assessment={a} allowed={allowed} onOpenGrid={onOpenGrid} onOpenReport={onOpenReport} onDelete={onDelete} />
    </div>
  )
}

type RowActionProps = Pick<AssessmentRowProps, 'assessment' | 'onOpenGrid' | 'onOpenReport' | 'onDelete'> & {
  allowed: { finalize: boolean; unlock: boolean }
}

function RowAction({ assessment: a, allowed, onOpenGrid, onOpenReport, onDelete }: RowActionProps) {
  const base = 'shrink-0 rounded-xl px-3 py-1.5 text-xs font-bold transition-colors'
  let action

  /* A finalized assessment is read-only, so its report is the useful thing to open; before
     ADR 0012 retired reports-generated, that status held this action. Whoever may unlock it
     (a Director of Studies, or the subject's HOD) also gets its grid, where Unlock is. */
  if (a.status === 'finalized') {
    action = (
      <>
        {allowed.unlock && (
          <button
            type="button"
            onClick={onOpenGrid}
            className={`${base} border border-border text-muted-foreground hover:bg-muted hover:text-foreground`}
          >
            Open grid
          </button>
        )}
        <button
          type="button"
          onClick={onOpenReport}
          className={`${base} border border-success/25 bg-success/10 text-success hover:bg-success/20`}
        >
          View report
        </button>
      </>
    )
  }
  /* Complete and awaiting a finalize; offered as such only to someone who may finalize it. */
  else if (a.status === 'complete' && allowed.finalize) {
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
        {a.status === 'in-progress' || a.status === 'complete' ? 'Continue' : 'Open'}
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

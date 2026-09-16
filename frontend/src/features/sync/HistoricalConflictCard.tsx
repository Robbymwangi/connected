import type { HistoricalConflict } from '../../fixtures/conflicts'
import { formatDateTime } from '../../lib/time'
import { formatMark } from '../../lib/grading'
import { describeReferral, sideOf } from '../../lib/conflicts'
import { ProposalThread } from './ConflictActions'
import { ConflictHeader } from './ConflictHeader'
import { ConflictSides, ValueBlock } from './ConflictSides'

type HistoricalConflictCardProps = {
  conflict: HistoricalConflict
}

/* A settled conflict: what each side held, what was settled on, who decided, and
   why. Read-only: history is the audit record. A wrong mark is corrected in the
   grid as a new mutation (ADR 0002). */
export function HistoricalConflictCard({ conflict }: HistoricalConflictCardProps) {
  const { resolution: r } = conflict
  const verdict =
    r.kind === 'auto' ? 'Auto-resolved: both sides agreed'
    : r.kind === 'self' ? `Settled by ${r.by} (own edits)`
    : r.kind === 'agreed' ? `Proposed by ${r.proposedBy}, accepted by ${r.acceptedBy}`
    : `Moderated by ${r.by}`
  const choice = r.kind === 'auto' ? null : r.choice
  const keptEdit = choice?.kind === 'side' ? choice.editId : null
  const keptMine = r.kind === 'auto' || keptEdit === conflict.mine.editId
  const keptTheirs = r.kind === 'auto' || keptEdit === conflict.theirs.editId
  /* An agreed note is already in the proposal thread; a moderator's is not. */
  const note = r.kind === 'moderated' ? r.note : undefined
  const describe = (c: typeof choice & {}) => {
    if (c.kind === 'corrected') return `corrected to ${formatMark(c.mark)}`
    const side = sideOf(conflict, c.editId)
    return side ? `${formatMark(side.mark)} (${side.who}'s mark)` : 'an unknown edit'
  }

  return (
    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
      <ConflictHeader student={conflict.student} criterion={conflict.criterion} assessment={conflict.assessment}>
        <div className="shrink-0 text-right">
          <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">{verdict}</p>
          <p className="mt-0.5 text-[10px] text-muted-foreground/70">{formatDateTime(conflict.resolvedAt)}</p>
        </div>
      </ConflictHeader>

      <ConflictSides
        mine={conflict.mine}
        theirs={conflict.theirs}
        mineEmphasis={keptMine ? 'kept' : 'discarded'}
        theirsEmphasis={keptTheirs ? 'kept' : 'discarded'}
      >
        {choice?.kind === 'corrected' && <ValueBlock label="Corrected" mark={choice.mark} emphasis="kept" />}
      </ConflictSides>

      {(conflict.proposals.length > 0 || conflict.referral || note) && (
        <div className="flex flex-col gap-2 px-4 pb-4">
          {conflict.proposals.length > 0 && <ProposalThread proposals={conflict.proposals} describe={describe} lastIsPending={false} />}
          {conflict.referral && (
            <p className="text-xs font-medium text-warning">{describeReferral(conflict.referral)}</p>
          )}
          {note && (
            <div className="rounded-xl border border-success/30 bg-success/[0.06] px-3 py-2.5">
              <p className="text-xs leading-relaxed text-foreground/80 italic">"{note}"</p>
              <p className="mt-1 text-[10px] font-medium text-muted-foreground">{r.kind === 'moderated' ? r.by : ''}</p>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

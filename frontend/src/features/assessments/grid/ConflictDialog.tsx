import { TriangleAlert } from 'lucide-react'
import { Modal } from '../../../components/Modal'
import type { ActiveConflict } from '../../../fixtures/conflicts'
import { currentUser } from '../../../fixtures/user'
import type { Mark } from '../../../lib/grading'
import { formatMark } from '../../../lib/grading'

type ConflictDialogProps = {
  conflict: ActiveConflict | null
  mine: Mark | null
  max: number
  onKeepMine: () => void
  onAcceptTheirs: () => void
  onClose: () => void
}

/* Two values for one mark, and a choice. The dates are shown so the teacher knows
   what happened; they do not decide anything, because clocks on different devices
   do not agree. */
export function ConflictDialog({ conflict, mine, max, onKeepMine, onAcceptTheirs, onClose }: ConflictDialogProps) {
  return (
    <Modal
      open={conflict !== null}
      onClose={onClose}
      title="Mark conflict"
      size="sm"
      headerExtra={<TriangleAlert className="size-4 text-danger" />}
      footer={
        <>
          <button
            type="button"
            onClick={onKeepMine}
            className="flex-1 rounded-xl border border-border py-2.5 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
          >
            Keep mine
          </button>
          <button
            type="button"
            onClick={onAcceptTheirs}
            className="flex-1 rounded-xl bg-danger py-2.5 text-sm font-bold text-danger-foreground transition-opacity hover:opacity-90"
          >
            Accept theirs
          </button>
        </>
      }
    >
      {conflict && (
        <>
          <p className="mb-4 text-xs text-muted-foreground">
            {conflict.student} · {conflict.criterion}
          </p>
          <div className="mb-4 grid grid-cols-2 gap-3">
            <ValueTile
              label="Your entry"
              who={currentUser.fullName}
              when={conflict.myDate}
              value={formatMark(mine ?? conflict.myMark)}
              max={max}
            />
            <ValueTile
              label="Conflicting"
              who={conflict.otherTeacher}
              when={conflict.otherDate}
              value={formatMark(conflict.otherMark)}
              max={max}
              danger
            />
          </div>
          <p className="text-center text-xs leading-relaxed text-muted-foreground">
            Accepting replaces your entry and marks the cell as synced.
          </p>
        </>
      )}
    </Modal>
  )
}

function ValueTile({
  label,
  who,
  when,
  value,
  max,
  danger = false,
}: {
  label: string
  who: string
  when: string
  value: string
  max: number
  danger?: boolean
}) {
  return (
    <div
      className={`rounded-xl border px-4 py-4 text-center ${
        danger ? 'border-danger/25 bg-danger/5' : 'border-border bg-muted/30'
      }`}
    >
      <p
        className={`mb-1 text-[10px] font-bold tracking-wider uppercase ${
          danger ? 'text-danger/80' : 'text-muted-foreground'
        }`}
      >
        {label}
      </p>
      <p className="mb-2 text-xs text-muted-foreground">{who}</p>
      <p className={`font-display text-3xl font-black tabular ${danger ? 'text-danger' : 'text-foreground'}`}>
        {value || '–'}
      </p>
      <p className="mt-1 text-[10px] text-muted-foreground">/{max}</p>
      <p className="mt-2 text-[10px] text-muted-foreground/70">{when}</p>
    </div>
  )
}

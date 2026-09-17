import { Grid3x3 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Button } from '../../components/Button'
import type { ActiveConflict, Choice } from '../../fixtures/conflicts'
import type { Subject } from '../../fixtures/rubrics'
import { ConflictActions } from './ConflictActions'
import { ConflictHeader } from './ConflictHeader'
import { ConflictSides } from './ConflictSides'

const FADE_MS = 400

type ActiveConflictCardProps = {
  conflict: ActiveConflict
  subject: Subject
  highlighted: boolean
  onResolve: (choice: Choice, note: string) => void
  onPropose: (choice: Choice, note: string) => void
  onAccept: () => void
  onRefer: () => void
  onOpenGrid: () => void
}

/* One unsettled conflict: both sides, and whatever ADR 0002 lets this user do
   about it. Settling fades the card out; proposing leaves it in place with the
   proposal shown. */
export function ActiveConflictCard({ conflict, subject, highlighted, onResolve, onPropose, onAccept, onRefer, onOpenGrid }: ActiveConflictCardProps) {
  const [leaving, setLeaving] = useState(false)

  /* One settlement per card: the timer ref is the guard, so it holds even before
     React re-renders with leaving set. The decision is applied after the fade, or
     at once if the card unmounts first; it is never dropped. */
  const pending = useRef<{ timer: ReturnType<typeof setTimeout>; apply: () => void } | null>(null)
  const settle = (apply: () => void) => {
    if (pending.current) return
    const run = () => {
      pending.current = null
      apply()
    }
    pending.current = { timer: setTimeout(run, FADE_MS), apply: run }
    setLeaving(true)
  }
  useEffect(() => () => {
    if (!pending.current) return
    clearTimeout(pending.current.timer)
    pending.current.apply()
  }, [])

  const card = (
    <div
      className={`overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-all duration-300 ${
        leaving ? 'scale-[0.98] opacity-0' : ''
      }`}
    >
      <ConflictHeader student={conflict.student} criterion={conflict.criterion} assessment={conflict.assessment}>
        <Button onClick={onOpenGrid} aria-label="Open in marking grid" title="Open in marking grid">
          <Grid3x3 className="size-4" />
        </Button>
      </ConflictHeader>

      <ConflictSides mine={conflict.mine} theirs={conflict.theirs} mineEmphasis="contested" theirsEmphasis="contested" />

      <div inert={leaving}>
        <ConflictActions
          conflict={conflict}
          subject={subject}
          onResolve={(choice, note) => settle(() => onResolve(choice, note))}
          onPropose={onPropose}
          onAccept={() => settle(onAccept)}
          onRefer={onRefer}
        />
      </div>
    </div>
  )

  return highlighted ? <div className="orbit orbit--live rounded-2xl">{card}</div> : card
}

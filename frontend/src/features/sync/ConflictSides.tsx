import type { ReactNode } from 'react'
import type { ConflictSide } from '../../fixtures/conflicts'
import { formatMark, type Mark } from '../../lib/grading'
import { formatDateTime } from '../../lib/time'

type Emphasis = 'contested' | 'kept' | 'discarded' | 'plain'

const BOX: Record<Emphasis, string> = {
  contested: 'border-danger/35 bg-muted/20',
  kept: 'border-success/30 bg-success/[0.06]',
  discarded: 'border-border/40 bg-muted/30',
  plain: 'border-border/50 bg-muted/40',
}
const LABEL: Record<Emphasis, string> = {
  contested: 'text-danger',
  kept: 'text-success',
  discarded: 'text-muted-foreground',
  plain: 'text-muted-foreground',
}
const VALUE: Record<Emphasis, string> = {
  contested: 'text-foreground',
  kept: 'text-success',
  discarded: 'text-muted-foreground/60 line-through decoration-1',
  plain: 'text-foreground',
}

type ValueBlockProps = {
  label: string
  mark: Mark
  at?: string
  receivedAt?: string
  emphasis: Emphasis
}

/* One value in a conflict: whose, what, when. Label and colour together. "When" is the
   server's receipt time, shown plainly, then the device's own claim, always labelled as
   the device's (docs/spec/sync-protocol.md, "Display discipline"). */
export function ValueBlock({ label, mark, at, receivedAt, emphasis }: ValueBlockProps) {
  return (
    <div className={`rounded-xl border px-3 py-3 ${BOX[emphasis]}`}>
      <p className={`mb-2 truncate text-[10px] font-semibold tracking-wider uppercase ${LABEL[emphasis]}`}>
        {label}
      </p>
      <p className={`font-display text-2xl leading-none font-bold tabular ${VALUE[emphasis]}`}>
        {formatMark(mark) || '–'}
      </p>
      {receivedAt && <p className="mt-1.5 text-[10px] leading-snug text-muted-foreground">Received {formatDateTime(receivedAt)}</p>}
      {at && (
        <p className={`${receivedAt ? 'mt-0.5' : 'mt-1.5'} text-[10px] leading-snug text-muted-foreground/70`}>
          Device time {formatDateTime(at)}
        </p>
      )}
    </div>
  )
}

type ConflictSidesProps = {
  mine: ConflictSide
  theirs: ConflictSide
  mineEmphasis: Emphasis
  theirsEmphasis: Emphasis
  children?: ReactNode
}

export function ConflictSides({ mine, theirs, mineEmphasis, theirsEmphasis, children }: ConflictSidesProps) {
  return (
    <div className={`grid gap-2 px-4 py-3 ${children ? 'grid-cols-3' : 'grid-cols-2'}`}>
      <ValueBlock label={mine.who} mark={mine.mark} at={mine.at} receivedAt={mine.receivedAt} emphasis={mineEmphasis} />
      <ValueBlock label={theirs.who} mark={theirs.mark} at={theirs.at} receivedAt={theirs.receivedAt} emphasis={theirsEmphasis} />
      {children}
    </div>
  )
}

import { useEffect, useRef, useState } from 'react'
import type { GridCell } from '../../../fixtures/marks'
import { formatMark, parseMarkInput, type Mark } from '../../../lib/grading'

export type GridMove = 'next-row' | 'prev-row' | 'next-cell' | 'prev-cell'

type MarkCellProps = {
  cell: GridCell
  max: number
  editing: boolean
  focused: boolean
  contested: boolean
  onFocus: () => void
  onCommit: (mark: Mark) => void
  onMove: (move: GridMove) => void
  onOpenConflict: () => void
}

/* One cell of the marking grid.

   Editing: a text input with the default keyboard: type=number cannot take "A" for
   absent, and inputMode=numeric can hide the letter keys on a tablet. Every
   keystroke is parsed; a valid value commits at once, an invalid one stays in the
   box with a danger ring and commits nothing. Esc puts back what the cell held when
   it was focused; blur drops any invalid text.

   Viewing: the mark, with a badge when it is contested or not yet synced. */
export function MarkCell({
  cell,
  max,
  editing,
  focused,
  contested,
  onFocus,
  onCommit,
  onMove,
  onOpenConflict,
}: MarkCellProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [draft, setDraft] = useState<string | null>(null)
  const [invalid, setInvalid] = useState(false)
  const revertTo = useRef<Mark>(cell.mark)

  useEffect(() => {
    if (editing && focused) {
      inputRef.current?.focus()
      inputRef.current?.select()
    }
  }, [editing, focused])

  const shown = draft ?? formatMark(cell.mark)

  const change = (text: string) => {
    setDraft(text)
    const result = parseMarkInput(text, max)
    setInvalid(!result.ok)
    if (result.ok) onCommit(result.mark)
  }

  const revert = () => {
    onCommit(revertTo.current)
    setDraft(formatMark(revertTo.current))
    setInvalid(false)
    inputRef.current?.select()
  }

  const keyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    const moves: Record<string, GridMove | undefined> = {
      Enter: 'next-row',
      ArrowDown: 'next-row',
      ArrowUp: 'prev-row',
      Tab: e.shiftKey ? 'prev-cell' : 'next-cell',
    }
    const move = moves[e.key]
    if (move) {
      e.preventDefault()
      onMove(move)
    } else if (e.key === 'Escape') {
      e.preventDefault()
      revert()
    }
  }

  if (editing) {
    return (
      <input
        ref={inputRef}
        type="text"
        autoComplete="off"
        value={shown}
        aria-invalid={invalid || undefined}
        aria-label={`Mark out of ${max}`}
        onFocus={() => {
          revertTo.current = cell.mark
          setDraft(formatMark(cell.mark))
          onFocus()
        }}
        onBlur={() => {
          setDraft(null)
          setInvalid(false)
        }}
        onChange={(e) => change(e.target.value)}
        onKeyDown={keyDown}
        className={`w-16 rounded-lg border bg-background px-1 py-1 text-center text-sm font-semibold tabular transition-colors focus:ring-2 focus:outline-none ${
          invalid
            ? 'border-danger text-danger focus:ring-danger/30'
            : 'border-primary/40 text-foreground focus:ring-primary/30'
        }`}
      />
    )
  }

  const text = formatMark(cell.mark)
  const absent = cell.mark.kind === 'absent'

  return (
    <div className="flex flex-col items-center gap-0.5">
      {contested ? (
        <button
          type="button"
          onClick={onOpenConflict}
          className="flex flex-col items-center gap-0.5 rounded-md px-1.5 py-0.5 text-sm font-semibold text-danger tabular transition-colors hover:bg-danger/10"
        >
          {text || <span className="text-muted-foreground/40">–</span>}
          <span className="text-[8px] font-bold uppercase">conflict</span>
        </button>
      ) : (
        <>
          <span
            className={`text-sm font-semibold tabular ${absent ? 'text-warning' : 'text-foreground'}`}
          >
            {text || <span className="text-muted-foreground/40">–</span>}
          </span>
          {cell.sync === 'local' && (
            <span className="text-[8px] font-bold text-warning uppercase">local</span>
          )}
        </>
      )}
    </div>
  )
}

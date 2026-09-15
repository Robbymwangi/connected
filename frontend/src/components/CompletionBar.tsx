type CompletionBarProps = {
  entered: number
  total: number
}

/* Entered over total. Full is success, most of the way is brand, behind is danger. */
export function CompletionBar({ entered, total }: CompletionBarProps) {
  const pct = total > 0 ? Math.round((entered / total) * 100) : 0
  const fill = pct === 100 ? 'bg-success' : pct >= 60 ? 'bg-primary' : 'bg-danger'

  return (
    <div className="flex min-w-0 items-center gap-2">
      <div
        role="progressbar"
        aria-valuenow={entered}
        aria-valuemin={0}
        aria-valuemax={total}
        className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted/60"
      >
        <div className={`h-full rounded-full transition-all ${fill}`} style={{ width: `${pct}%` }} />
      </div>
      <span className="shrink-0 text-[10px] text-muted-foreground tabular">
        {entered}/{total}
      </span>
    </div>
  )
}

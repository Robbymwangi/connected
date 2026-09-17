import { CHART_COLORS, type ChartColor } from '../../components/charts/chartTypes'

export type BarListRow = {
  label: string
  /* 0 to 100. */
  pct: number
  detail?: string
  color?: ChartColor
  compare?: { pct: number; detail?: string }
}

type BarListProps = {
  rows: BarListRow[]
  /* Reference mark in percent, drawn as a line across every bar. */
  reference?: number
}

/* Ranked categories as labelled horizontal bars: label, bar, value. Reads better
   than a chart for a handful of named rows, and needs no axis. */
export function BarList({ rows, reference }: BarListProps) {
  return (
    <ul className="flex flex-col gap-3">
      {rows.map((r) => (
        <li key={r.label} className="grid grid-cols-[minmax(6rem,10rem)_1fr_auto] items-center gap-3 text-xs">
          <span className="truncate font-medium text-foreground">{r.label}</span>
          <div className="relative flex flex-col gap-1">
            <div className="h-2 overflow-hidden rounded-full bg-muted/60">
              <div className="h-full rounded-full" style={{ width: `${r.pct}%`, background: CHART_COLORS[r.color ?? 'primary'] }} />
            </div>
            {r.compare && (
              <div className="h-2 overflow-hidden rounded-full bg-muted/60">
                <div className="h-full rounded-full" style={{ width: `${r.compare.pct}%`, background: CHART_COLORS['muted'] }} />
              </div>
            )}
            {reference !== undefined && (
              <span aria-hidden className="absolute inset-y-0 w-px bg-foreground/30" style={{ left: `${reference}%` }} />
            )}
          </div>
          <span className="text-right text-muted-foreground tabular">
            <span className="font-semibold text-foreground">{Math.round(r.pct)}%</span>
            {r.detail && <span className="ml-1">{r.detail}</span>}
            {r.compare && (
              <span className="block">
                {Math.round(r.compare.pct)}%{r.compare.detail && <span className="ml-1">{r.compare.detail}</span>}
              </span>
            )}
          </span>
        </li>
      ))}
    </ul>
  )
}

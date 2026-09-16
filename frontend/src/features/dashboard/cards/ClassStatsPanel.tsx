import { ArrowDown, ArrowUp } from 'lucide-react'
import type { ClassStats } from '../../../fixtures/dashboard'

type Figure = { current: number; previous: number }

type StatTileProps = {
  label: string
  figure: Figure
  /* Colour of the label and headline figure: mean in info, cohort in warning. */
  accentClass: string
}

function StatTile({ label, figure, accentClass }: StatTileProps) {
  const delta = figure.current - figure.previous
  const improved = delta >= 0

  return (
    <div className="flex flex-col gap-1 rounded-xl border border-border/50 bg-muted/40 px-4 py-3.5">
      <span className={`text-[10px] font-bold tracking-wider uppercase ${accentClass}`}>
        {label}
      </span>
      <div className="mt-1 flex items-end gap-2">
        <span
          className={`font-display text-3xl leading-none font-black tracking-tight tabular ${accentClass}`}
        >
          {figure.current.toFixed(1)}
        </span>
        <span className="mb-1 text-sm text-muted-foreground">/100</span>
      </div>
      <div className="mt-1 flex items-center gap-1.5">
        <span
          className={`flex items-center gap-0.5 text-xs font-bold tabular ${
            improved ? 'text-success' : 'text-danger'
          }`}
        >
          {improved ? <ArrowUp className="size-4" /> : <ArrowDown className="size-4" />}
          {Math.abs(delta).toFixed(1)}
        </span>
        <span className="text-[10px] text-muted-foreground">from {figure.previous.toFixed(1)}</span>
      </div>
    </div>
  )
}

export function ClassStatsPanel({ stats }: { stats: ClassStats }) {
  return (
    <div className="flex flex-1 flex-col gap-4 px-5 pt-4 pb-5">
      <p className="text-xs text-muted-foreground">
        Assessment: <span className="font-semibold text-foreground">{stats.assessment}</span>
        <span className="text-muted-foreground/60"> vs. {stats.comparedTo}</span>
      </p>
      <div className="grid grid-cols-2 gap-3">
        <StatTile label="Class Mean" figure={stats.mean} accentClass="text-info" />
        <StatTile label="Your Cohort" figure={stats.cohort} accentClass="text-warning" />
      </div>
    </div>
  )
}

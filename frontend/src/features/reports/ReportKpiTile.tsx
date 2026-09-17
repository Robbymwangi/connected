type Side = { value: string; sub?: string; n: number }

type ReportKpiTileProps = {
  label: string
  primary: Side
  compare?: Side
  primaryLabel: string
  compareLabel?: string
  warn?: boolean
}

/* One headline figure, or two side by side in compare mode. The value wears text
   colour; only the warning state tints it. */
export function ReportKpiTile({ label, primary, compare, primaryLabel, compareLabel, warn = false }: ReportKpiTileProps) {
  const value = (side: Side, name?: string) => (
    <div className="min-w-0 flex-1">
      {name && <p className="mb-1 truncate text-[10px] font-semibold text-muted-foreground">{name}</p>}
      <p className={`font-display text-2xl leading-none font-bold tabular ${warn ? 'text-warning' : 'text-foreground'}`}>{side.value}</p>
      <p className="mt-1 text-[11px] text-muted-foreground">
        {side.sub ? `${side.sub} · ` : ''}n = {side.n}
      </p>
    </div>
  )
  return (
    <div className={`rounded-2xl border bg-card px-4 py-4 shadow-sm ${warn ? 'border-warning/30' : 'border-border'}`}>
      <p className="mb-2 text-[10px] font-bold tracking-wider text-muted-foreground uppercase">{label}</p>
      {compare ? (
        <div className="flex gap-4">
          {value(primary, primaryLabel)}
          <div className="w-px bg-border/60" />
          {value(compare, compareLabel)}
        </div>
      ) : (
        value(primary)
      )}
    </div>
  )
}

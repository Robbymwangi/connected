type KpiTileProps = {
  label: string
  value: string | number
}

export function KpiTile({ label, value }: KpiTileProps) {
  return (
    <div className="flex min-w-0 flex-1 flex-col gap-1 rounded-xl border border-border/50 bg-muted/40 px-3 py-3">
      <span className="text-xs leading-tight text-muted-foreground">{label}</span>
      <span className="text-xl leading-tight font-bold text-foreground tabular">{value}</span>
    </div>
  )
}

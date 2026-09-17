import { CHART_COLORS, type ChartColor, type Series } from './chartTypes'

type ChartTooltipProps = {
  category: string
  series: Series[]
  index: number
  format: (value: number) => string
  /* Pixel position inside the chart container. */
  x: number
  /* Flip to the left when near the right edge. */
  flip: boolean
  /* The hovered category's own colour, when categories carry the identity. */
  categoryColor?: ChartColor
}

/* One row per series for the hovered category. Values wear text tokens; the
   swatch carries the series identity. */
export function ChartTooltip({ category, series, index, format, x, flip, categoryColor }: ChartTooltipProps) {
  return (
    <div
      role="status"
      className="pointer-events-none absolute top-2 z-10 min-w-28 rounded-lg border border-border bg-card px-2.5 py-2 text-xs shadow-lg backdrop-blur-md"
      style={flip ? { right: `calc(100% - ${x}px + 8px)` } : { left: x + 8 }}
    >
      <p className="mb-1 font-semibold text-foreground">{category}</p>
      {series.map((s) => {
        const v = s.values[index]
        return (
          <p key={s.id} className="flex items-center gap-1.5 text-muted-foreground">
            <span aria-hidden className="inline-block size-2 rounded-full" style={{ background: CHART_COLORS[categoryColor ?? s.color] }} />
            <span className="flex-1">{s.label}</span>
            <span className="font-semibold text-foreground tabular">{v === null ? '–' : format(v)}</span>
          </p>
        )
      })}
    </div>
  )
}

import { useId, useRef, useState, type ReactNode } from 'react'
import { useWidth } from '../../lib/useSize'
import { linear, niceTicks } from '../../lib/scale'
import { CHART_COLORS, MARGIN, type ChartProps } from './chartTypes'
import { ChartTooltip } from './ChartTooltip'

export type Frame = {
  width: number
  height: number
  plot: { x0: number; x1: number; y0: number; y1: number }
  y: (value: number) => number
  ticks: number[]
  /* Centre x of category i. */
  cx: (i: number) => number
  /* Left edge and width of category i's slot. */
  slot: (i: number) => { x: number; w: number }
  active: number | null
}

type ChartFrameProps = ChartProps & {
  children: (frame: Frame) => ReactNode
}

/* Everything a bar or line chart shares: measuring the container, the value axis
   with round ticks, the recessive grid, the reference line, the legend for two or
   more series, hover and keyboard selection of a category, and the tooltip. The
   chart itself draws its marks through the render prop. */
export function ChartFrame({ categories, series, max, referenceLine, height = 160, format = String, label, categoryColors, children }: ChartFrameProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const width = useWidth(containerRef)
  const [active, setActive] = useState<number | null>(null)
  const helpId = useId()

  const top = max ?? Math.max(referenceLine?.value ?? 0, ...series.flatMap((s) => s.values.filter((v): v is number => v !== null)), 0)
  const ticks = niceTicks(top)
  const yMax = ticks[ticks.length - 1]

  const plot = { x0: MARGIN.left, x1: Math.max(MARGIN.left, width - MARGIN.right), y0: MARGIN.top, y1: height - MARGIN.bottom }
  const y = linear([0, yMax], [plot.y1, plot.y0])
  const n = categories.length
  const step = n > 0 ? (plot.x1 - plot.x0) / n : 0
  const slot = (i: number) => ({ x: plot.x0 + i * step, w: step })
  const cx = (i: number) => plot.x0 + (i + 0.5) * step

  const indexAt = (clientX: number) => {
    const rect = containerRef.current?.getBoundingClientRect()
    if (!rect || step === 0) return null
    const i = Math.floor((clientX - rect.left - plot.x0) / step)
    return i >= 0 && i < n ? i : null
  }
  const onKey = (e: React.KeyboardEvent) => {
    if (n === 0) return
    if (e.key === 'ArrowRight') setActive((a) => (a === null ? 0 : Math.min(n - 1, a + 1)))
    else if (e.key === 'ArrowLeft') setActive((a) => (a === null ? n - 1 : Math.max(0, a - 1)))
    else if (e.key === 'Escape') setActive(null)
    else return
    e.preventDefault()
  }

  const frame: Frame = { width, height, plot, y, ticks, cx, slot, active }

  return (
    <div className="flex flex-col gap-2">
      {series.length > 1 && (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-muted-foreground" aria-label="Series">
          {series.map((s) => (
            <li key={s.id} className="flex items-center gap-1.5">
              <span aria-hidden className="inline-block h-1 w-3 rounded-full" style={{ background: CHART_COLORS[s.color] }} />
              {s.label}
            </li>
          ))}
        </ul>
      )}
      <p id={helpId} className="sr-only">
        Focus the chart and use the left and right arrow keys to read each value; Escape clears the selection.
      </p>
      <div ref={containerRef} className="relative w-full" style={{ height }}>
        {width > 0 && (
          <svg
            width={width}
            height={height}
            role="img"
            aria-label={label}
            aria-describedby={helpId}
            tabIndex={0}
            onKeyDown={onKey}
            onBlur={() => setActive(null)}
            onPointerMove={(e) => setActive(indexAt(e.clientX))}
            onPointerLeave={() => setActive(null)}
            className="block overflow-visible rounded-md focus:ring-2 focus:ring-primary/30 focus:outline-none"
          >
            <title>{label}</title>
            {ticks.map((t) => (
              <g key={t}>
                <line x1={plot.x0} x2={plot.x1} y1={y(t)} y2={y(t)} stroke="var(--chart-grid)" strokeWidth={1} />
                <text x={plot.x0 - 6} y={y(t)} dy="0.35em" textAnchor="end" fontSize={10} fill="var(--chart-axis-text)" className="tabular">
                  {format(t)}
                </text>
              </g>
            ))}
            {categories.map((c, i) => (
              <text key={c + i} x={cx(i)} y={height - 8} textAnchor="middle" fontSize={10} fill="var(--chart-axis-text)">
                {c}
              </text>
            ))}
            {referenceLine && (
              <g>
                <line x1={plot.x0} x2={plot.x1} y1={y(referenceLine.value)} y2={y(referenceLine.value)} stroke={CHART_COLORS[referenceLine.color ?? 'warning']} strokeWidth={1} strokeDasharray="4 3" />
                <text
                  x={plot.x1}
                  y={y(referenceLine.value) - 4}
                  textAnchor="end"
                  fontSize={10}
                  fill={CHART_COLORS[referenceLine.color ?? 'warning']}
                  stroke="var(--card)"
                  strokeWidth={3}
                  paintOrder="stroke"
                >
                  {referenceLine.label}
                </text>
              </g>
            )}
            {active !== null && (
              <rect x={slot(active).x} y={plot.y0} width={slot(active).w} height={plot.y1 - plot.y0} fill="var(--muted)" />
            )}
            {children(frame)}
          </svg>
        )}
        {active !== null && width > 0 && (
          <ChartTooltip
            category={categories[active]}
            series={series}
            index={active}
            format={format}
            x={cx(active)}
            flip={cx(active) > width * 0.65}
            categoryColor={series.length === 1 ? categoryColors?.[active] : undefined}
          />
        )}
      </div>
    </div>
  )
}

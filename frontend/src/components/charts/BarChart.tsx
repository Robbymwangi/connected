import { band } from '../../lib/scale'
import { ChartFrame } from './ChartFrame'
import { CHART_COLORS, type ChartProps } from './chartTypes'

/* Grouped bars: one band per category, one bar per series inside it, rounded at
   the data end, a 2px surface gap between bars. */
export function BarChart(props: ChartProps) {
  /* Per-category colour only makes sense for a single series; with several, the
     series colour is the identity. */
  const categoryColors = props.series.length === 1 ? props.categoryColors : undefined
  return (
    <ChartFrame {...props}>
      {({ y, slot, plot }) =>
        props.categories.map((_, i) => {
          const { x, w } = slot(i)
          const bars = band(props.series.length, [x + w * 0.15, x + w * 0.85], 0.15)
          return (
            <g key={i}>
              {props.series.map((s, j) => {
                const v = s.values[i]
                if (v === null) return null
                const top = y(v)
                const h = Math.max(0, plot.y1 - top)
                const fill = CHART_COLORS[categoryColors?.[i] ?? s.color]
                return (
                  <rect key={s.id} x={bars.at(j)} y={top} width={bars.width} height={h} rx={h > 4 ? 3 : 0} fill={fill} stroke="var(--card)" strokeWidth={1} />
                )
              })}
            </g>
          )
        })
      }
    </ChartFrame>
  )
}

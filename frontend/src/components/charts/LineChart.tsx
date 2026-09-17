import { linePath } from '../../lib/scale'
import { ChartFrame } from './ChartFrame'
import { CHART_COLORS, type ChartProps } from './chartTypes'

/* Values over ordered categories. 2px lines, 8px markers on the active category,
   gaps where a value is missing. */
export function LineChart(props: ChartProps) {
  return (
    <ChartFrame {...props}>
      {({ y, cx, active }) => (
        <>
          {props.series.map((s) => {
            const points = s.values.map((v, i) => (v === null ? null : { x: cx(i), y: y(v) }))
            return (
              <g key={s.id}>
                <path d={linePath(points)} fill="none" stroke={CHART_COLORS[s.color]} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
                {points.map((p, i) =>
                  p && (
                    <circle key={i} cx={p.x} cy={p.y} r={active === i ? 4 : 2.5} fill={CHART_COLORS[s.color]} stroke="var(--card)" strokeWidth={active === i ? 2 : 0} />
                  ),
                )}
              </g>
            )
          })}
        </>
      )}
    </ChartFrame>
  )
}

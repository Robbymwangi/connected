/* Scale arithmetic for the SVG charts (ADR 0003). Pure functions: a scale maps a
   data value to a pixel position inside a range, and "nice" ticks give an axis
   round numbers. */

export type Scale = (value: number) => number

/* Linear: [d0, d1] onto [r0, r1]. A degenerate domain maps everything to r0. */
export function linear(domain: [number, number], range: [number, number]): Scale {
  const [d0, d1] = domain
  const [r0, r1] = range
  const span = d1 - d0
  return (v) => (span === 0 ? r0 : r0 + ((v - d0) / span) * (r1 - r0))
}

/* Band: n categories across a range with gaps. Returns the left edge of a band
   and the band width. padding is the fraction of one step left as gap. */
export function band(
  count: number,
  range: [number, number],
  padding = 0.3,
): { at: (index: number) => number; width: number; step: number } {
  const [r0, r1] = range
  if (count <= 0) return { at: () => r0, width: 0, step: 0 }
  const step = (r1 - r0) / count
  const width = step * (1 - padding)
  return { at: (i) => r0 + i * step + (step - width) / 2, width, step }
}

/* Round ticks from zero to at or above `max` on a 1/2/5 grid, in roughly `count`
   intervals (so about count + 1 ticks including zero). The last tick is at or
   above the top so a bar never overshoots the axis. */
export function niceTicks(max: number, count = 5): number[] {
  if (max <= 0) return [0]
  const rough = max / count
  const power = Math.pow(10, Math.floor(Math.log10(rough)))
  const unit = [1, 2, 5, 10].find((u) => u * power >= rough) ?? 10
  const step = unit * power
  const ticks: number[] = []
  for (let t = 0; t < max + step; t += step) ticks.push(Number(t.toFixed(10)))
  return ticks
}

/* SVG path for a polyline through points, skipping gaps (null) without bridging. */
export function linePath(points: Array<{ x: number; y: number } | null>): string {
  let d = ''
  let pen = false
  for (const p of points) {
    if (!p) {
      pen = false
      continue
    }
    d += `${pen ? 'L' : 'M'}${p.x} ${p.y}`
    pen = true
  }
  return d
}

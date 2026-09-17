/* Shared shapes for the SVG charts (ADR 0003). */

export type ChartColor = 'primary' | 'info' | 'warning' | 'success' | 'danger' | 'muted'

/* Series colour to token. Never a literal colour. */
export const CHART_COLORS: Record<ChartColor, string> = {
  primary: 'var(--chart-line)',
  info: 'var(--chart-mean)',
  warning: 'var(--chart-threshold)',
  success: 'var(--success)',
  danger: 'var(--danger)',
  muted: 'var(--muted-foreground)',
}

export type Series = {
  id: string
  label: string
  color: ChartColor
  /* One value per category; null is a gap. */
  values: Array<number | null>
}

export type ReferenceLine = {
  value: number
  label: string
  color?: ChartColor
}

export type ChartProps = {
  categories: string[]
  series: Series[]
  /* Top of the value axis. Defaults to the largest value, rounded up. */
  max?: number
  referenceLine?: ReferenceLine
  height?: number
  /* Formats a value for the tooltip and axis. */
  format?: (value: number) => string
  /* One sentence for assistive technology. */
  label: string
  /* Colour per category for a single series whose categories are the identity
     (performance levels, say). Overrides the series colour, bars and tooltip alike. */
  categoryColors?: ChartColor[]
}

export const MARGIN = { top: 12, right: 12, bottom: 24, left: 34 }

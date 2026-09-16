/* Marks, totals, and performance levels. Pure functions; the grid renders what these
   return and never computes a score itself. */

/* What a cell holds. Absent is an explicit value, never a null or a zero, so it can
   be excluded from every denominator by name. Empty means not yet entered. */
export type Mark =
  | { kind: 'empty' }
  | { kind: 'score'; value: number }
  | { kind: 'absent' }

export const EMPTY: Mark = { kind: 'empty' }
export const ABSENT: Mark = { kind: 'absent' }
export const score = (value: number): Mark => ({ kind: 'score', value })

/* CBC performance levels, from the share of the maximum achieved. */
export type PerformanceLevel = 'EE' | 'ME' | 'AE' | 'BE'

export const PERFORMANCE_LEVELS: Record<PerformanceLevel, { label: string; minShare: number }> = {
  EE: { label: 'Exceeding Expectations', minShare: 0.8 },
  ME: { label: 'Meeting Expectations', minShare: 0.6 },
  AE: { label: 'Approaching Expectations', minShare: 0.4 },
  BE: { label: 'Below Expectations', minShare: 0 },
}

export function performanceLevel(total: number, max: number): PerformanceLevel {
  if (max <= 0) throw new RangeError('performanceLevel: max must be positive')
  const share = total / max
  if (share >= PERFORMANCE_LEVELS.EE.minShare) return 'EE'
  if (share >= PERFORMANCE_LEVELS.ME.minShare) return 'ME'
  if (share >= PERFORMANCE_LEVELS.AE.minShare) return 'AE'
  return 'BE'
}

/* A row's total exists only when every criterion holds a score. An empty or absent
   cell makes the total undefined rather than smaller: a partial sum would read as a
   low mark, and an absent student has no mark at all. */
export function rowTotal(marks: Mark[]): number | null {
  let total = 0
  for (const mark of marks) {
    if (mark.kind !== 'score') return null
    total += mark.value
  }
  return marks.length > 0 ? total : null
}

/* Parsing what a teacher types into a cell. Rejected input is reported, never
   silently corrected: clamping 100 to a /10 column would hide a slip. */
export type ParseFailure = 'not-a-number' | 'not-an-integer' | 'negative' | 'over-max'

export type ParseResult =
  | { ok: true; mark: Mark }
  | { ok: false; reason: ParseFailure }

export const ABSENT_KEY = 'a'

export function parseMarkInput(raw: string, max: number): ParseResult {
  const text = raw.trim()
  if (text === '') return { ok: true, mark: EMPTY }
  if (text.toLowerCase() === ABSENT_KEY) return { ok: true, mark: ABSENT }
  if (!/^-?\d+(\.\d+)?$/.test(text)) return { ok: false, reason: 'not-a-number' }
  /* "-0" parses to negative zero; store plain zero. */
  const value = Number(text) || 0
  if (!Number.isInteger(value)) return { ok: false, reason: 'not-an-integer' }
  if (value < 0) return { ok: false, reason: 'negative' }
  if (value > max) return { ok: false, reason: 'over-max' }
  return { ok: true, mark: score(value) }
}

/* What a mark looks like in a cell or a table. */
export function formatMark(mark: Mark): string {
  switch (mark.kind) {
    case 'score':
      return String(mark.value)
    case 'absent':
      return 'ABS'
    case 'empty':
      return ''
  }
}

/* How many categories to skip between axis labels so none overlap. Text is
   measured by character count (10px sans averages about 5.5px a character), the
   cheap estimate that needs no DOM, with a gap so neighbours never touch. Every
   category is still reachable through the tooltip, so skipping loses nothing. */
const CHAR_WIDTH = 5.5
const GAP = 8

export function labelStride(categories: string[], slotWidth: number): number {
  if (categories.length === 0 || slotWidth <= 0) return 1
  const widest = Math.max(...categories.map((c) => c.length)) * CHAR_WIDTH + GAP
  return Math.max(1, Math.ceil(widest / slotWidth))
}

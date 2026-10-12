import { describe, expect, it } from 'vitest'
import { labelStride } from './axisLabels'

describe('labelStride', () => {
  it('shows every label when they fit', () => {
    expect(labelStride(['CAT 1', 'CAT 2', 'CAT 3'], 120)).toBe(1)
  })

  it('skips labels when slots are narrower than the widest label', () => {
    const categories = Array.from({ length: 30 }, (_, i) => `English CAT ${i}`)
    expect(labelStride(categories, 20)).toBeGreaterThan(3)
  })

  it('never returns less than one, whatever the inputs', () => {
    expect(labelStride([], 50)).toBe(1)
    expect(labelStride(['A'], 0)).toBe(1)
  })
})

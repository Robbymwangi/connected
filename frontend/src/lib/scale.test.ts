import { describe, expect, it } from 'vitest'
import { band, linear, linePath, niceTicks } from './scale'

describe('linear', () => {
  it('maps the domain ends to the range ends and interpolates between', () => {
    const s = linear([0, 100], [10, 210])
    expect(s(0)).toBe(10)
    expect(s(100)).toBe(210)
    expect(s(50)).toBe(110)
  })
  it('supports an inverted range, as SVG y axes need', () => {
    const s = linear([0, 100], [120, 20])
    expect(s(0)).toBe(120)
    expect(s(100)).toBe(20)
  })
  it('does not divide by zero on a degenerate domain', () => {
    expect(linear([5, 5], [0, 100])(5)).toBe(0)
  })
})

describe('band', () => {
  it('lays out equal bands with gaps inside the range', () => {
    const b = band(4, [0, 400], 0.5)
    expect(b.step).toBe(100)
    expect(b.width).toBe(50)
    expect(b.at(0)).toBe(25)
    expect(b.at(3)).toBe(325)
    expect(b.at(3) + b.width).toBeLessThanOrEqual(400)
  })
  it('handles zero categories', () => {
    expect(band(0, [0, 100]).width).toBe(0)
  })
})

describe('niceTicks', () => {
  it('produces round steps that reach the top of the domain', () => {
    expect(niceTicks(100)).toEqual([0, 20, 40, 60, 80, 100])
    expect(niceTicks(28, 5)).toEqual([0, 10, 20, 30])
    expect(niceTicks(7)).toEqual([0, 2, 4, 6, 8])
  })
  it('never returns an empty axis', () => {
    expect(niceTicks(0)).toEqual([0])
  })
})

describe('linePath', () => {
  it('draws through points and lifts the pen at a gap', () => {
    expect(linePath([{ x: 0, y: 1 }, { x: 2, y: 3 }, null, { x: 4, y: 5 }])).toBe('M0 1L2 3M4 5')
    expect(linePath([])).toBe('')
  })
})

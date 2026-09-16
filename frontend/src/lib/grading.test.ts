import { describe, expect, it } from 'vitest'
import {
  ABSENT,
  EMPTY,
  formatMark,
  parseMarkInput,
  performanceLevel,
  rowTotal,
  score,
} from './grading'

describe('performanceLevel', () => {
  it('bands on the share of the maximum at 80, 60, and 40 percent', () => {
    expect(performanceLevel(80, 100)).toBe('EE')
    expect(performanceLevel(79, 100)).toBe('ME')
    expect(performanceLevel(60, 100)).toBe('ME')
    expect(performanceLevel(59, 100)).toBe('AE')
    expect(performanceLevel(40, 100)).toBe('AE')
    expect(performanceLevel(39, 100)).toBe('BE')
    expect(performanceLevel(0, 100)).toBe('BE')
  })
  it('uses the share, not the raw total, so rubrics of different sizes compare', () => {
    expect(performanceLevel(48, 60)).toBe('EE')
    expect(performanceLevel(24, 60)).toBe('AE')
  })
  it('refuses a non-positive maximum rather than dividing by it', () => {
    expect(() => performanceLevel(10, 0)).toThrow(RangeError)
  })
})

describe('rowTotal', () => {
  it('sums a full row of scores', () => {
    expect(rowTotal([score(10), score(5), score(0)])).toBe(15)
  })
  it('is undefined while any criterion is still empty', () => {
    expect(rowTotal([score(10), EMPTY, score(5)])).toBeNull()
  })
  it('is undefined when any criterion is absent, so absence never reads as zero', () => {
    expect(rowTotal([score(10), ABSENT, score(5)])).toBeNull()
    expect(rowTotal([ABSENT, ABSENT])).toBeNull()
  })
  it('is undefined for an empty rubric', () => {
    expect(rowTotal([])).toBeNull()
  })
})

describe('parseMarkInput', () => {
  const max = 20

  it('accepts integers from zero to the maximum inclusive', () => {
    expect(parseMarkInput('0', max)).toEqual({ ok: true, mark: score(0) })
    expect(parseMarkInput('20', max)).toEqual({ ok: true, mark: score(20) })
    expect(parseMarkInput(' 7 ', max)).toEqual({ ok: true, mark: score(7) })
    expect(Object.is((parseMarkInput('-0', max) as { mark: { value: number } }).mark.value, 0)).toBe(true)
  })
  it('treats blank as empty and A as absent, either case', () => {
    expect(parseMarkInput('', max)).toEqual({ ok: true, mark: EMPTY })
    expect(parseMarkInput('a', max)).toEqual({ ok: true, mark: ABSENT })
    expect(parseMarkInput('A', max)).toEqual({ ok: true, mark: ABSENT })
  })
  it('rejects over-maximum input instead of clamping it', () => {
    expect(parseMarkInput('21', max)).toEqual({ ok: false, reason: 'over-max' })
    expect(parseMarkInput('100', 10)).toEqual({ ok: false, reason: 'over-max' })
  })
  it('rejects negatives, fractions, and text', () => {
    expect(parseMarkInput('-1', max)).toEqual({ ok: false, reason: 'negative' })
    expect(parseMarkInput('7.5', max)).toEqual({ ok: false, reason: 'not-an-integer' })
    expect(parseMarkInput('x', max)).toEqual({ ok: false, reason: 'not-a-number' })
    expect(parseMarkInput('1e2', max)).toEqual({ ok: false, reason: 'not-a-number' })
  })
})

describe('formatMark', () => {
  it('shows scores, ABS for absent, and nothing for empty', () => {
    expect(formatMark(score(12))).toBe('12')
    expect(formatMark(ABSENT)).toBe('ABS')
    expect(formatMark(EMPTY)).toBe('')
  })
})

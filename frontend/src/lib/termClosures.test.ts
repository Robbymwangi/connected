import { describe, expect, it } from 'vitest'
import type { LocalRecord } from './localDatabase'
import { isTermClosed } from './termClosures'

const closure = (over: Partial<LocalRecord> = {}): LocalRecord => ({
  id: 'c1',
  version: 1,
  classId: 'class-4w',
  year: 2026,
  term: 1,
  ...over,
})

describe('isTermClosed', () => {
  const closures = [closure()]

  it('is true for the stream, year, and term that was closed', () => {
    expect(isTermClosed(closures, 'class-4w', 2026, 1)).toBe(true)
  })

  it('is false for another stream, year, or term', () => {
    expect(isTermClosed(closures, 'class-4e', 2026, 1)).toBe(false)
    expect(isTermClosed(closures, 'class-4w', 2025, 1)).toBe(false)
    expect(isTermClosed(closures, 'class-4w', 2026, 2)).toBe(false)
  })

  it('ignores a closure that an unlock has since deleted', () => {
    expect(isTermClosed([closure({ deletedAt: '2026-10-01T00:00:00Z' })], 'class-4w', 2026, 1)).toBe(false)
  })
})

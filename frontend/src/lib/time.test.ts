import { describe, expect, it } from 'vitest'
import { formatDateTime, formatLongDate, formatRelative, greetingFor } from './time'

const at = (hour: number) => new Date(2026, 7, 28, hour, 0, 0)

describe('greetingFor', () => {
  it('is morning before noon', () => {
    expect(greetingFor(at(0))).toBe('Good morning')
    expect(greetingFor(at(11))).toBe('Good morning')
  })
  it('is afternoon from noon until five', () => {
    expect(greetingFor(at(12))).toBe('Good afternoon')
    expect(greetingFor(at(16))).toBe('Good afternoon')
  })
  it('is evening from five', () => {
    expect(greetingFor(at(17))).toBe('Good evening')
    expect(greetingFor(at(23))).toBe('Good evening')
  })
})

describe('formatLongDate', () => {
  it('writes weekday, day, month, and year in British order', () => {
    expect(formatLongDate(new Date(2026, 7, 28))).toBe('Friday, 28 August 2026')
  })
})

describe('formatDateTime', () => {
  it('writes a compact British date with a 24-hour time', () => {
    expect(formatDateTime('2026-08-27T14:32:00')).toBe('27 Aug 2026, 14:32')
  })
})

describe('formatRelative', () => {
  const now = new Date(2026, 7, 28, 12, 0, 0)
  const ago = (ms: number) => new Date(now.getTime() - ms)
  const minute = 60_000
  const hour = 60 * minute
  const day = 24 * hour

  it('treats anything under a minute as just now', () => {
    expect(formatRelative(now, now)).toBe('just now')
    expect(formatRelative(ago(59_000), now)).toBe('just now')
  })
  it('counts minutes under an hour', () => {
    expect(formatRelative(ago(minute), now)).toBe('1m ago')
    expect(formatRelative(ago(59 * minute), now)).toBe('59m ago')
  })
  it('counts hours under a day', () => {
    expect(formatRelative(ago(hour), now)).toBe('1h ago')
    expect(formatRelative(ago(2 * hour), now)).toBe('2h ago')
    expect(formatRelative(ago(23 * hour), now)).toBe('23h ago')
  })
  it('counts days beyond that', () => {
    expect(formatRelative(ago(day), now)).toBe('1d ago')
    expect(formatRelative(ago(10 * day), now)).toBe('10d ago')
  })
  /* A unit is reported only once it has fully passed: just under a threshold still
     reads as the smaller unit, so the line never overstates the drift. */
  it('floors rather than rounds at every threshold', () => {
    expect(formatRelative(ago(59_999), now)).toBe('just now')
    expect(formatRelative(ago(minute - 1), now)).toBe('just now')
    expect(formatRelative(ago(2 * minute - 1), now)).toBe('1m ago')
    expect(formatRelative(ago(hour - 1), now)).toBe('59m ago')
    expect(formatRelative(ago(2 * hour - 1), now)).toBe('1h ago')
    expect(formatRelative(ago(day - 1), now)).toBe('23h ago')
    expect(formatRelative(ago(2 * day - 1), now)).toBe('1d ago')
  })
  it('never reports the future, which clock skew between devices can produce', () => {
    expect(formatRelative(new Date(now.getTime() + 5 * minute), now)).toBe('just now')
  })
})

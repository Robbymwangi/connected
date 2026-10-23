import { describe, expect, it } from 'vitest'
import { clockAnomalies, CLOCK_TOLERANCE_MS } from './clockAnomalies'

const received = '2026-10-20T10:00:00.000000Z'
const shift = (iso: string, ms: number) => new Date(Date.parse(iso) + ms).toISOString()

describe('clockAnomalies', () => {
  it('flags nothing for an honest edit, however long it waited offline before syncing', () => {
    const at = shift(received, -5 * 24 * 3_600_000)
    expect(clockAnomalies({ at, receivedAt: received, baseReceivedAt: shift(at, -3_600_000) })).toEqual([])
  })

  it('flags a device time after the server received the edit, once past the tolerance', () => {
    expect(clockAnomalies({ at: shift(received, 3 * 3_600_000), receivedAt: received })).toEqual(['after-receipt'])
  })

  it('flags a device time before the version it edited existed, once past the tolerance', () => {
    expect(clockAnomalies({ at: shift(received, -3_600_000), receivedAt: received, baseReceivedAt: shift(received, -60_000) })).toEqual(['before-base'])
  })

  it('allows a clock that is off by up to the tolerance, either way', () => {
    expect(clockAnomalies({ at: shift(received, CLOCK_TOLERANCE_MS), receivedAt: received })).toEqual([])
    expect(clockAnomalies({ at: shift(received, CLOCK_TOLERANCE_MS + 1), receivedAt: received })).toEqual(['after-receipt'])
    const base = shift(received, -60_000)
    expect(clockAnomalies({ at: shift(base, -CLOCK_TOLERANCE_MS), receivedAt: received, baseReceivedAt: base })).toEqual([])
    expect(clockAnomalies({ at: shift(base, -CLOCK_TOLERANCE_MS - 1), receivedAt: received, baseReceivedAt: base })).toEqual(['before-base'])
  })

  it('checks only what is on record: no receipt times, or an unreadable device time, flag nothing', () => {
    expect(clockAnomalies({ at: shift(received, 3 * 3_600_000) })).toEqual([])
    expect(clockAnomalies({ at: '', receivedAt: received, baseReceivedAt: received })).toEqual([])
  })
})

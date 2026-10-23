/* The two timestamp anomaly flags on a conflict side (docs/spec/sync-protocol.md, Audit
   timestamps). A device's `at` is its own claim; the server's receipt times are not. A
   claim that does not add up against them is flagged for a person to look at, nothing more:
   no rule reads `at`, so a flag never changes who wins or what the mark is.

   - after-receipt: the device says the edit was made after the server received it.
   - before-base: the device says the edit was made before the server received the version
     it was edited from, so before that version existed.

   Either needs the gap to pass a tolerance, so an honest clock that is merely a little off
   is never flagged. Derived when shown, never stored: both inputs are already on record. */

/* Phones and laptops set their clocks from the network and drift by seconds a day, so an
   honest device stays well inside this; a wrong timezone or a clock never set is off by
   hours. A starting value, not a measurement (the spec's proposal). */
export const CLOCK_TOLERANCE_MS = 5 * 60_000

export type ClockAnomaly = 'after-receipt' | 'before-base'

export type TimedSide = { at: string; receivedAt?: string; baseReceivedAt?: string }

export function clockAnomalies(side: TimedSide): ClockAnomaly[] {
  const at = Date.parse(side.at)
  if (Number.isNaN(at)) return []
  const flags: ClockAnomaly[] = []
  const received = side.receivedAt ? Date.parse(side.receivedAt) : NaN
  if (!Number.isNaN(received) && at - received > CLOCK_TOLERANCE_MS) flags.push('after-receipt')
  const base = side.baseReceivedAt ? Date.parse(side.baseReceivedAt) : NaN
  if (!Number.isNaN(base) && base - at > CLOCK_TOLERANCE_MS) flags.push('before-base')
  return flags
}

export const CLOCK_ANOMALY_TEXT: Record<ClockAnomaly, string> = {
  'after-receipt': "Device time is after the server received this edit. That device's clock may be wrong.",
  'before-base': "Device time is before the value it edited existed. That device's clock may be wrong.",
}

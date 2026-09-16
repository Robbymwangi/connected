import { PERFORMANCE_LEVELS, type PerformanceLevel } from '../lib/grading'
import { StatusPill, type StatusTone } from './StatusPill'

const LEVEL_TONES: Record<PerformanceLevel, StatusTone> = {
  EE: 'success',
  ME: 'info',
  AE: 'warning',
  BE: 'danger',
}

/* The level code in its tone, with the full name for assistive technology. */
export function LevelBadge({ level }: { level: PerformanceLevel }) {
  return (
    <StatusPill tone={LEVEL_TONES[level]} size="sm" aria-label={PERFORMANCE_LEVELS[level].label}>
      {level}
    </StatusPill>
  )
}

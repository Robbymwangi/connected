import type { StatusTone } from '../../components/StatusPill'
import type { LifecycleStatus } from '../../fixtures/assessments'

/* Label and tone per lifecycle status. Label and colour always travel together. */
export const STATUS_META: Record<LifecycleStatus, { label: string; tone: StatusTone }> = {
  scheduled: { label: 'Scheduled', tone: 'neutral' },
  'in-progress': { label: 'In Progress', tone: 'info' },
  complete: { label: 'Needs Finalizing', tone: 'warning' },
  finalized: { label: 'Finalized', tone: 'success' },
  'reports-generated': { label: 'Reports Generated', tone: 'primary' },
}

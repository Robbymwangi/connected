import type { StatusTone } from '../components/StatusPill'

/* Notifications are the surface for long-running and asynchronous work: report
   generation, sync conflicts, enrolment changes. The intended source is records the
   device already holds (job status rows synced like any other table), so the feed
   works offline. Until then, a fixture. */

export type NotificationKind = 'sync-conflict' | 'submission' | 'report-ready' | 'enrolment'

export type Notification = {
  id: string
  kind: NotificationKind
  tone: StatusTone
  title: string
  body: string
  time: string
  unread: boolean
}

export const notifications: Notification[] = [
  {
    id: 'n-1',
    kind: 'sync-conflict',
    tone: 'warning',
    title: 'Sync conflict detected',
    body: 'English CAT 2 has a conflict with the server copy.',
    time: '2 min ago',
    unread: true,
  },
  {
    id: 'n-2',
    kind: 'submission',
    tone: 'info',
    title: 'Grade 4W: Amina submitted',
    body: 'Assessment response received for Term 2 CAT.',
    time: '18 min ago',
    unread: true,
  },
  {
    id: 'n-3',
    kind: 'report-ready',
    tone: 'success',
    title: 'Report generated',
    body: 'Grade 5 All end-of-term summary is ready to download.',
    time: '1h ago',
    unread: false,
  },
  {
    id: 'n-4',
    kind: 'enrolment',
    tone: 'info',
    title: 'New student added',
    body: 'Liam Osei has been enrolled in Grade 4W.',
    time: 'Yesterday',
    unread: false,
  },
]

import { liveQuery } from 'dexie'
import { useEffect, useState } from 'react'
import type { StatusTone } from '../components/StatusPill'
import { localDatabaseFor, type LocalRecord } from './localDatabase'

export type NotificationKind = 'sync-conflict' | 'submission' | 'report-ready' | 'enrolment' | 'edit-blocked'

export type NotificationItem = {
  id: string
  kind: NotificationKind
  tone: StatusTone
  title: string
  body: string
  unread: boolean
}

export type NotificationsState =
  | { status: 'loading'; items: NotificationItem[] }
  | { status: 'ready'; items: NotificationItem[] }
  | { status: 'error'; items: NotificationItem[] }

const KINDS = new Set<NotificationKind>(['sync-conflict', 'submission', 'report-ready', 'enrolment', 'edit-blocked'])
const TONES = new Set<StatusTone>(['success', 'info', 'neutral', 'warning', 'danger', 'primary'])

type NotificationSnapshot = {
  userId: string
  rows: LocalRecord[]
  status: NotificationsState['status']
}

export function mapNotificationRows(rows: LocalRecord[], userId: string): NotificationItem[] {
  return rows.flatMap((row): NotificationItem[] => {
    if (row.deletedAt != null || row.userId !== userId) return []
    if (
      typeof row.id !== 'string' ||
      typeof row.kind !== 'string' ||
      !KINDS.has(row.kind as NotificationKind) ||
      typeof row.tone !== 'string' ||
      !TONES.has(row.tone as StatusTone) ||
      typeof row.title !== 'string' ||
      typeof row.body !== 'string' ||
      typeof row.unread !== 'boolean'
    ) {
      return []
    }

    return [{
      id: row.id,
      kind: row.kind as NotificationKind,
      tone: row.tone as StatusTone,
      title: row.title,
      body: row.body,
      unread: row.unread,
    }]
  })
}

export function useNotifications(userId: string): NotificationsState {
  const database = localDatabaseFor(userId)
  const [snapshot, setSnapshot] = useState<NotificationSnapshot>({ userId, rows: [], status: 'loading' })

  useEffect(() => {
    const subscription = liveQuery(() => database.notifications.where('userId').equals(userId).toArray()).subscribe({
      next: (nextRows) => {
        setSnapshot({ userId, rows: nextRows, status: 'ready' })
      },
      error: () => setSnapshot({ userId, rows: [], status: 'error' }),
    })

    return () => subscription.unsubscribe()
  }, [database, userId])

  const current = snapshot.userId === userId ? snapshot : { userId, rows: [], status: 'loading' as const }
  return { status: current.status, items: mapNotificationRows(current.rows, userId) }
}
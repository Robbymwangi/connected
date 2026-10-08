import { describe, expect, it } from 'vitest'
import type { LocalRecord } from './localDatabase'
import { mapNotificationRows } from './useNotifications'

describe('mapNotificationRows', () => {
  it('keeps valid active rows for the current user and omits server timestamps', () => {
    const rows: LocalRecord[] = [
      { id: 'n1', version: 1, userId: 'u1', kind: 'edit-blocked', tone: 'danger', title: 'Edit not applied', body: 'Assessment finalized.', unread: true, createdAt: '2026-10-08T09:00:00Z' },
      { id: 'n2', version: 1, userId: 'u2', kind: 'sync-conflict', tone: 'warning', title: 'Other user', body: 'Private.', unread: true },
      { id: 'n3', version: 1, userId: 'u1', kind: 'report-ready', tone: 'success', title: 'Deleted', body: 'Old.', unread: false, deletedAt: '2026-10-08T09:00:00Z' },
      { id: 'n4', version: 1, userId: 'u1', kind: 'unknown', tone: 'info', title: 'Unknown kind', body: 'Skip.', unread: true },
    ]

    expect(mapNotificationRows(rows, 'u1')).toEqual([
      { id: 'n1', kind: 'edit-blocked', tone: 'danger', title: 'Edit not applied', body: 'Assessment finalized.', unread: true },
    ])
  })
})
import { liveQuery } from 'dexie'
import { useEffect, useRef, useState } from 'react'
import { localDatabaseFor } from '../lib/localDatabase'
import { readRecentAccesses, rememberRecentAccess, type RecentAccess } from '../lib/recentAccess'

export function useRecentAccesses(userId: string, current: RecentAccess | null): RecentAccess[] {
  const database = localDatabaseFor(userId)
  const [items, setItems] = useState<RecentAccess[]>([])
  const recordedKey = useRef<string | null>(null)

  useEffect(() => {
    const subscription = liveQuery(() => readRecentAccesses(database)).subscribe({
      next: setItems,
      error: () => setItems([]),
    })
    return () => subscription.unsubscribe()
  }, [database])

  useEffect(() => {
    if (!current) {
      recordedKey.current = null
      return
    }
    if (recordedKey.current === current.key) return

    recordedKey.current = current.key
    void rememberRecentAccess(database, current).catch(() => {
      recordedKey.current = null
    })
  }, [current, database])

  return items
}
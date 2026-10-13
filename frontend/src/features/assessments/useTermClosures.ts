import { liveQuery } from 'dexie'
import { useEffect, useState } from 'react'
import { localDatabaseFor, type LocalRecord } from '../../lib/localDatabase'

/* Every term closure this device holds, live, so a closure the pull delivers, or an unlock
   that removes one, changes what the create dialog offers without a reload. Empty until the
   first read; the server refuses regardless, so the brief gap costs nothing. */
export function useTermClosures(userId: string): readonly LocalRecord[] {
  const database = localDatabaseFor(userId)
  const [closures, setClosures] = useState<readonly LocalRecord[]>([])

  useEffect(() => {
    const subscription = liveQuery(() => database.term_closures.toArray()).subscribe({
      next: setClosures,
      error: () => setClosures([]),
    })
    return () => subscription.unsubscribe()
  }, [database])

  return closures
}

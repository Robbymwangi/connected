import { liveQuery } from 'dexie'
import { useEffect, useMemo, useState, useSyncExternalStore } from 'react'
import { localDatabaseFor } from './localDatabase'
import { readSyncRows, summarizeSync, type SyncRows, type SyncState } from './syncState'
import { syncStatusFor } from './syncRunner'

/* The signed-in account's sync state: the outbox counts and notices through a Dexie
   live query, the runner's phase from memory, and how many conflicts need this person,
   which the store works out and passes in. Untested like useConnectivity; what it
   computes is in syncState.ts, which is. */
export function useSyncState(userId: string, conflictsNeedingYou = 0): SyncState {
  const database = localDatabaseFor(userId)
  const store = syncStatusFor(database)
  const status = useSyncExternalStore(store.subscribe, store.getSnapshot)
  const [rows, setRows] = useState<SyncRows | null>(null)

  useEffect(() => {
    const subscription = liveQuery(() => readSyncRows(database)).subscribe({
      next: setRows,
      error: () => undefined,
    })
    return () => subscription.unsubscribe()
  }, [database])

  return useMemo(() => summarizeSync(rows, status, conflictsNeedingYou), [rows, status, conflictsNeedingYou])
}

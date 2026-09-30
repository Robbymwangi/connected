import type { Session, SessionStorage } from './session'

/* A dedicated database, not the local store's: 3.3 builds the real offline
   store and picks its own name and schema, and this must not pre-empt that
   decision by squatting on it. This one holds exactly one row, the signed-in
   session, and nothing else.

   IndexedDB, not sessionStorage: sessionStorage clears when the installed
   PWA's window closes, and "zero connectivity is the normal resting state"
   (AGENTS.md) means a teacher who closes the app offline must still be
   signed in when they reopen it, not stuck unable to sign back in until a
   connection returns. The raw browser API is used directly; no dependency
   was added for this. */

const DB_NAME = 'connected-session'
const DB_VERSION = 1
const STORE = 'session'
const KEY = 'current'

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)
    request.onupgradeneeded = () => {
      request.result.createObjectStore(STORE)
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error as Error)
    /* Only possible if another tab holds an older connection open across a
       future version bump; DB_VERSION has never changed yet, so this is
       currently unreachable in practice, but a promise this never settles
       would hang every caller forever if it ever were. */
    request.onblocked = () => reject(new Error('connected-session: database open blocked by another tab'))
  })
}

/* Opens its own connection and closes it again once the transaction settles,
   one way or the other: nothing here is long-lived enough to justify holding
   a connection open between calls. */
async function runTransaction<T>(
  mode: IDBTransactionMode,
  run: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
  const db = await openDb()
  try {
    return await new Promise<T>((resolve, reject) => {
      const tx = db.transaction(STORE, mode)
      const request = run(tx.objectStore(STORE))
      tx.onerror = () => reject(tx.error as Error)
      tx.onabort = () => reject(tx.error ?? new Error('connected-session: transaction aborted'))
      tx.oncomplete = () => resolve(request.result)
    })
  } finally {
    db.close()
  }
}

/* Every operation swallows a storage failure (private browsing, disabled
   storage, a blocked upgrade) rather than throwing: the session still works
   for this page load either way, it just will not survive a reload. That
   degrades to the same experience the ticket's sessionStorage alternative
   would have given by default, not to a crash. */
export function createIndexedDbSessionStorage(): SessionStorage {
  return {
    async load() {
      try {
        const result = await runTransaction<Session | undefined>('readonly', (store) => store.get(KEY))
        return result ?? null
      } catch {
        return null
      }
    },
    async save(session) {
      try {
        await runTransaction('readwrite', (store) => store.put(session, KEY))
      } catch {
        // Unavailable storage is not fatal; see the note above.
      }
    },
    async clear() {
      try {
        await runTransaction('readwrite', (store) => store.delete(KEY))
      } catch {
        // Nothing to clear if storage was never reachable.
      }
    },
  }
}

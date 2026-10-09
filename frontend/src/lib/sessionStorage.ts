import type { Session, SessionStorage } from './session'
import { localDatabase, type LocalDatabase } from './localDatabase'

const KEY = 'current' as const
const LEGACY_DB_NAME = 'connected-session'
const LEGACY_STORE = 'session'

function openLegacyDatabase(): Promise<IDBDatabase | null> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(LEGACY_DB_NAME)
    let didNotExist = false
    request.onupgradeneeded = (event) => {
      if (event.oldVersion === 0) {
        didNotExist = true
        request.transaction?.abort()
      }
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => {
      if (didNotExist) resolve(null)
      else reject(request.error ?? new Error('connected-session: database open failed'))
    }
    request.onblocked = () => reject(new Error('connected-session: database open blocked by another tab'))
  })
}

function isSession(value: unknown): value is Session {
  if (typeof value !== 'object' || value === null) return false
  const session = value as { token?: unknown; user?: { id?: unknown } }
  return typeof session.token === 'string' && typeof session.user?.id === 'string'
}

async function readLegacySession(): Promise<Session | null> {
  const database = await openLegacyDatabase()
  if (!database) return null

  try {
    if (!database.objectStoreNames.contains(LEGACY_STORE)) return null
    const value = await new Promise<unknown>((resolve, reject) => {
      const transaction = database.transaction(LEGACY_STORE, 'readonly')
      const request = transaction.objectStore(LEGACY_STORE).get(KEY)
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error ?? new Error('connected-session: read failed'))
    })
    return isSession(value) ? value : null
  } finally {
    database.close()
  }
}

async function removeLegacySession(): Promise<void> {
  const database = await openLegacyDatabase()
  if (!database) return

  try {
    if (!database.objectStoreNames.contains(LEGACY_STORE)) return
    await new Promise<void>((resolve, reject) => {
      const transaction = database.transaction(LEGACY_STORE, 'readwrite')
      transaction.objectStore(LEGACY_STORE).delete(KEY)
      transaction.oncomplete = () => resolve()
      transaction.onerror = () => reject(transaction.error ?? new Error('connected-session: delete failed'))
      transaction.onabort = () => reject(transaction.error ?? new Error('connected-session: delete aborted'))
    })
  } finally {
    database.close()
  }
}

export function createDexieSessionStorage(database: LocalDatabase = localDatabase): SessionStorage {
  return {
    async load() {
      try {
        const stored = await database.sessions.get(KEY)
        if (stored) return stored.session
      } catch {
        // Try the pre-Dexie session database below.
      }

      try {
        const legacy = await readLegacySession()
        if (!legacy) return null
        try {
          await database.sessions.put({ key: KEY, session: legacy })
          await removeLegacySession()
        } catch {
          // The cached session can still serve this page load if persistence is unavailable.
        }
        return legacy
      } catch {
        return null
      }
    },
    async save(session) {
      try {
        await database.sessions.put({ key: KEY, session })
        await removeLegacySession()
      } catch {
        // Storage failure should not prevent a session from working for this page load.
      }
    },
    async clear() {
      try {
        await database.sessions.delete(KEY)
      } catch {
        // Nothing to clear if storage was never reachable.
      }
      try {
        await removeLegacySession()
      } catch {
        // The legacy store may be unavailable or blocked by another tab.
      }
    },
  }
}

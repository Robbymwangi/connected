import { useCallback, useSyncExternalStore } from 'react'
import type { Location } from './location'
import { parseLocation, pathFor } from './routes'

/* Location kept in step with the browser's history (ADR 0005). Reading goes
   through useSyncExternalStore so React re-renders on back and forward; writing
   pushes a history entry and notifies. */

const listeners = new Set<() => void>()
const notify = () => listeners.forEach((l) => l())

function subscribe(listener: () => void) {
  listeners.add(listener)
  window.addEventListener('popstate', listener)
  return () => {
    listeners.delete(listener)
    window.removeEventListener('popstate', listener)
  }
}

/* The URL is the source of truth; the snapshot is the string so React can compare
   it cheaply, and the Location is derived from it. */
const snapshot = () => window.location.pathname + window.location.search

export function useLocation(): [Location, (next: Location, opts?: { replace?: boolean }) => void] {
  const url = useSyncExternalStore(subscribe, snapshot, () => '/')
  const location = parseLocation(url.split('?')[0], url.includes('?') ? url.slice(url.indexOf('?')) : '')

  const navigate = useCallback((next: Location, opts?: { replace?: boolean }) => {
    const path = pathFor(next)
    if (path === snapshot()) return
    if (opts?.replace) window.history.replaceState(null, '', path)
    else window.history.pushState(null, '', path)
    notify()
  }, [])

  return [location, navigate]
}

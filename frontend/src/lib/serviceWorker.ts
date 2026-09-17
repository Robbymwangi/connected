import { useSyncExternalStore } from 'react'
import { registerSW } from 'virtual:pwa-register'

/* Registration and the update prompt for the service worker (ADR 0004). Workbox
   generates and manages the worker; this wires its update signal to the app.
   Production only: the dev build registers nothing (devOptions.enabled = false),
   so the dev server's live reload is not fought. */

let apply: (() => void) | null = null
const listeners = new Set<() => void>()

export function registerServiceWorker() {
  if (!import.meta.env.PROD) return
  const update = registerSW({
    onNeedRefresh() {
      /* A new version has installed and is waiting; reloading activates it. */
      apply = () => update(true)
      listeners.forEach((l) => l())
    },
  })
}

const subscribe = (listener: () => void) => {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/* The pending update's apply function, if any, for the shell to offer. */
export function useServiceWorkerUpdate(): (() => void) | null {
  return useSyncExternalStore(subscribe, () => apply, () => null)
}

import { useSyncExternalStore } from 'react'

/* Registration and the update prompt for the service worker (ADR 0004). Production
   only: in development any registered worker is removed so it cannot fight the dev
   server. */

type Update = { apply: () => void }

let pending: Update | null = null
const listeners = new Set<() => void>()
const announce = (u: Update | null) => {
  pending = u
  listeners.forEach((l) => l())
}
const subscribe = (listener: () => void) => {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return
  if (!import.meta.env.PROD) {
    navigator.serviceWorker.getRegistrations().then((rs) => rs.forEach((r) => r.unregister()))
    return
  }

  let reloading = false
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (reloading) return
    reloading = true
    window.location.reload()
  })

  navigator.serviceWorker.register('/sw.js').then((registration) => {
    const watch = (worker: ServiceWorker | null) => {
      if (!worker) return
      worker.addEventListener('statechange', () => {
        /* Installed with a controller already in place means a new version is
           waiting; without one, this is the first install and needs no prompt. */
        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
          announce({ apply: () => worker.postMessage('SKIP_WAITING') })
        }
      })
    }
    watch(registration.waiting)
    registration.addEventListener('updatefound', () => watch(registration.installing))
    if (registration.waiting && navigator.serviceWorker.controller) {
      announce({ apply: () => registration.waiting?.postMessage('SKIP_WAITING') })
    }
  })
}

/* The pending update, if any, for the shell to offer. An external store, so an
   update announced at any moment reaches the component. */
export function useServiceWorkerUpdate(): Update | null {
  return useSyncExternalStore(subscribe, () => pending, () => null)
}

import { useEffect, useEffectEvent, useRef, useState, useSyncExternalStore } from 'react'
import { connectivityOverride } from './connectivityOverride'
import { healthMonitor } from './health'

/* Connectivity is one fact and sync state is another; this hook reports only the
   first. Sync state comes from the outbox and is displayed separately.

   navigator.onLine is a link-layer signal: it reports true behind a captive portal
   with no route out. So "online" here means the browser reports a link and the API
   answered its last health probe (lib/health.ts, GET /api/health, one shared poll
   however many components use this hook). The browser going offline is believed at
   once, and coming back is not believed until the API has answered a probe since.
   The hook's return shape does not change. */

type Options = {
  /* Called when connectivity changes, from the event itself rather than from a
     render, so callers can react (a toast, say) without an effect on the value. */
  onChange?: (online: boolean) => void
}

export function useConnectivity({ onChange }: Options = {}) {
  const [online, setOnline] = useState(() => navigator.onLine)
  const reachable = useSyncExternalStore(healthMonitor.subscribe, healthMonitor.getSnapshot)

  /* Development-only manual override for demos and evaluation runs. Not a control a
     teacher can reach: the toggle is undefined outside development builds. Held in a
     shared store, not here, so the sync runner sees the same answer as the pill. */
  const override = useSyncExternalStore(connectivityOverride.subscribe, connectivityOverride.get)

  /* Callers hear about the effective value only when it changes: with the override
     set, a browser event or a probe result underneath it is not a change. */
  const lastNotified = useRef<boolean>(override ?? (online && reachable))
  const notify = useEffectEvent((browser: boolean, api: boolean) => {
    const effective = override ?? (browser && api)
    if (effective === lastNotified.current) return
    lastNotified.current = effective
    onChange?.(effective)
  })

  useEffect(() => {
    /* Probing on these events is the shared monitor's job (lib/health.ts), once for
       the whole app. Going offline makes it forget its last answer, so the snapshot
       read here is false until a probe after the reconnect succeeds: the hook never
       reports online on the strength of an answer from before the link dropped. */
    const goOnline = () => {
      setOnline(true)
      notify(true, healthMonitor.getSnapshot())
    }
    const goOffline = () => {
      setOnline(false)
      notify(false, healthMonitor.getSnapshot())
    }
    window.addEventListener('online', goOnline)
    window.addEventListener('offline', goOffline)
    const unsubscribe = healthMonitor.subscribe(() => notify(navigator.onLine, healthMonitor.getSnapshot()))
    return () => {
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
      unsubscribe()
    }
  }, [])

  const isOnline = override ?? (online && reachable)
  const toggleOverride = import.meta.env.DEV
    ? () => {
        const next = !isOnline
        connectivityOverride.set(next)
        lastNotified.current = next
        onChange?.(next)
      }
    : undefined

  return { isOnline, toggleOverride }
}

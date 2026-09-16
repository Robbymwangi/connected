import { useEffect, useEffectEvent, useRef, useState } from 'react'

/* Connectivity is one fact and sync state is another; this hook reports only the
   first. Sync state comes from the outbox and is displayed separately.

   navigator.onLine is a link-layer signal: it reports true behind a captive portal
   with no route out. The intended design adds a periodic lightweight request to the
   API on top of it. That request is not made yet because the API has no health route
   and this stage of the port makes no API calls; when it lands it belongs here, and
   the hook's return shape does not change. */

type Options = {
  /* Called when connectivity changes, from the event itself rather than from a
     render, so callers can react (a toast, say) without an effect on the value. */
  onChange?: (online: boolean) => void
}

export function useConnectivity({ onChange }: Options = {}) {
  const [online, setOnline] = useState(() => navigator.onLine)

  /* Development-only manual override for demos and evaluation runs. Not a control a
     teacher can reach: the toggle is undefined outside development builds. */
  const [override, setOverride] = useState<boolean | null>(null)

  /* Callers hear about the effective value only when it changes: with the override
     set, a browser event underneath it is not a change. */
  const lastNotified = useRef<boolean>(override ?? online)
  const notify = useEffectEvent((browser: boolean) => {
    const effective = override ?? browser
    if (effective === lastNotified.current) return
    lastNotified.current = effective
    onChange?.(effective)
  })

  useEffect(() => {
    const goOnline = () => {
      setOnline(true)
      notify(true)
    }
    const goOffline = () => {
      setOnline(false)
      notify(false)
    }
    window.addEventListener('online', goOnline)
    window.addEventListener('offline', goOffline)
    return () => {
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
    }
  }, [])

  const isOnline = override ?? online
  const toggleOverride = import.meta.env.DEV
    ? () => {
        const next = !isOnline
        setOverride(next)
        lastNotified.current = next
        onChange?.(next)
      }
    : undefined

  return { isOnline, toggleOverride }
}

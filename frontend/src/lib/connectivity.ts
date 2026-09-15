import { useEffect, useState } from 'react'

/* Connectivity is one fact and sync state is another; this hook reports only the
   first. Sync state comes from the outbox and is displayed separately.

   navigator.onLine is a link-layer signal: it reports true behind a captive portal
   with no route out. The intended design adds a periodic lightweight request to the
   API on top of it. That request is not made yet because the API has no health route
   and this stage of the port makes no API calls; when it lands it belongs here, and
   the hook's return shape does not change. */

export function useConnectivity() {
  const [online, setOnline] = useState(() => navigator.onLine)

  /* Development-only manual override for demos and evaluation runs. Not a control a
     teacher can reach: the toggle is undefined outside development builds. */
  const [override, setOverride] = useState<boolean | null>(null)

  useEffect(() => {
    const goOnline = () => setOnline(true)
    const goOffline = () => setOnline(false)
    window.addEventListener('online', goOnline)
    window.addEventListener('offline', goOffline)
    return () => {
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
    }
  }, [])

  const isOnline = override ?? online
  const toggleOverride = import.meta.env.DEV
    ? () => setOverride(!isOnline)
    : undefined

  return { isOnline, toggleOverride }
}

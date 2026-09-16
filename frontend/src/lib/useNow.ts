import { useEffect, useState } from 'react'

const MINUTE = 60_000

/* A Date that refreshes on an interval, so relative times ("synced 2h ago") keep
   up on a page nobody is touching. Without it the value is only as fresh as the
   last render, which on an idle dashboard can be many minutes old. */
export function useNow(intervalMs: number = MINUTE): Date {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), intervalMs)
    return () => clearInterval(timer)
  }, [intervalMs])

  return now
}

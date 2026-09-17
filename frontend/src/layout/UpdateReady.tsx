import { RefreshCw } from 'lucide-react'
import { useServiceWorkerUpdate } from '../lib/serviceWorker'

/* Offered when a new build has installed and is waiting. The user chooses when
   to switch; nothing reloads under them. */
export function UpdateReady() {
  const apply = useServiceWorkerUpdate()
  if (!apply) return null
  return (
    <div
      role="status"
      className="fixed bottom-6 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-2xl border border-border bg-card px-4 py-2.5 text-sm shadow-2xl backdrop-blur-md dialog-panel--open"
    >
      <RefreshCw className="size-4 text-primary" />
      <span className="text-foreground">A new version of ConnectED is ready.</span>
      <button
        type="button"
        onClick={apply}
        className="rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground transition-opacity hover:opacity-90"
      >
        Reload
      </button>
    </div>
  )
}

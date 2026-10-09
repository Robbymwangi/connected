import { Clock } from 'lucide-react'

/* Shown on a conflict whose view includes an action this device has queued and the
   server has not confirmed yet. It says nothing about the connection: the action is
   waiting its turn in the outbox, online or not. */
export function WaitingToSync() {
  return (
    <span className="inline-flex items-center gap-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
      <Clock className="size-3" aria-hidden="true" />
      Waiting to sync
    </span>
  )
}

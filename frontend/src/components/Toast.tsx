import { CheckCircle2, LoaderCircle, TriangleAlert } from 'lucide-react'
import type { StatusTone } from './StatusPill'

export type ToastKind = 'offline' | 'syncing' | 'resolved'

const TOASTS: Record<ToastKind, { tone: StatusTone; message: string }> = {
  offline: { tone: 'warning', message: 'You are offline; marks are being saved locally' },
  syncing: { tone: 'neutral', message: 'Back online; syncing changes' },
  resolved: { tone: 'success', message: 'Conflict resolved; change queued for sync' },
}

const TONE_CLASSES: Record<StatusTone, string> = {
  warning: 'bg-warning text-warning-foreground',
  success: 'bg-success text-success-foreground',
  danger: 'bg-danger text-danger-foreground',
  info: 'bg-info text-info-foreground',
  primary: 'bg-primary text-primary-foreground',
  neutral: 'bg-foreground text-background',
}

/* Transient status at the bottom of the viewport. Text and icon always together. */
export function Toast({ kind }: { kind: ToastKind }) {
  const { tone, message } = TOASTS[kind]
  return (
    <div
      role="status"
      className={`dialog-panel--open fixed bottom-6 left-1/2 z-50 flex max-w-[calc(100vw-2rem)] -translate-x-1/2 items-center gap-3 rounded-2xl px-5 py-3 text-sm font-medium shadow-2xl backdrop-blur-md ${TONE_CLASSES[tone]}`}
    >
      {kind === 'offline' && <TriangleAlert className="size-4 shrink-0" />}
      {kind === 'syncing' && <LoaderCircle className="size-4 shrink-0 animate-spin" />}
      {kind === 'resolved' && <CheckCircle2 className="size-4 shrink-0" />}
      <span>{message}</span>
    </div>
  )
}

import { Bell, X } from 'lucide-react'
import { useState } from 'react'
import { Popover } from '../components/Popover'
import type { StatusTone } from '../components/StatusPill'
import { notifications as seed } from '../fixtures/notifications'

const DOT_CLASSES: Record<StatusTone, string> = {
  success: 'bg-success',
  info: 'bg-info',
  neutral: 'bg-muted-foreground',
  warning: 'bg-warning',
  danger: 'bg-danger',
  primary: 'bg-primary',
}

type NotificationsPopupProps = {
  open: boolean
  onClose: () => void
}

export function NotificationsPopup({ open, onClose }: NotificationsPopupProps) {
  const [items, setItems] = useState(seed)
  const unreadCount = items.filter((n) => n.unread).length

  const markAllRead = () => setItems((prev) => prev.map((n) => ({ ...n, unread: false })))
  const dismiss = (id: string) => setItems((prev) => prev.filter((n) => n.id !== id))

  return (
    <Popover open={open} onClose={onClose} anchor="top-right" className="w-80 bg-background">
      <div className="flex items-center justify-between border-b border-border/60 px-4 py-3">
        <div className="flex items-center gap-2">
          <span className="text-sm font-semibold text-foreground">Notifications</span>
          {unreadCount > 0 && (
            <span className="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold text-primary-foreground tabular">
              {unreadCount}
            </span>
          )}
        </div>
        {unreadCount > 0 && (
          <button
            type="button"
            onClick={markAllRead}
            className="text-xs font-medium text-primary transition-opacity hover:opacity-80"
          >
            Mark all read
          </button>
        )}
      </div>

      <div className="max-h-72 divide-y divide-border/40 overflow-y-auto">
        {items.map((n) => (
          <div
            key={n.id}
            className={`group flex items-start gap-3 px-4 py-3 transition-colors hover:bg-muted/40 ${
              n.unread ? 'bg-primary/[0.03]' : ''
            }`}
          >
            <div className="relative mt-1.5 shrink-0">
              <div className={`size-2 rounded-full ${DOT_CLASSES[n.tone]}`} />
              {n.unread && (
                <div
                  className={`absolute inset-0 animate-ping rounded-full opacity-40 ${DOT_CLASSES[n.tone]}`}
                />
              )}
            </div>
            <div className="min-w-0 flex-1">
              <p
                className={`text-sm leading-tight font-medium ${
                  n.unread ? 'text-foreground' : 'text-muted-foreground'
                }`}
              >
                {n.title}
              </p>
              <p className="mt-0.5 text-xs leading-snug text-muted-foreground">{n.body}</p>
              <p className="mt-1 text-[10px] font-medium text-muted-foreground/70">{n.time}</p>
            </div>
            <button
              type="button"
              onClick={() => dismiss(n.id)}
              aria-label="Dismiss"
              className="mt-0.5 shrink-0 rounded p-0.5 text-muted-foreground opacity-0 transition-all group-hover:opacity-100 hover:text-foreground"
            >
              <X className="size-3" />
            </button>
          </div>
        ))}
        {items.length === 0 && (
          <div className="flex flex-col items-center justify-center gap-2 py-10">
            <div className="flex size-10 items-center justify-center rounded-full bg-muted/50 text-muted-foreground">
              <Bell className="size-5" />
            </div>
            <p className="text-sm text-muted-foreground">All caught up</p>
          </div>
        )}
      </div>

      <div className="border-t border-border/60 px-4 py-2.5">
        <button
          type="button"
          className="w-full py-1 text-center text-xs font-medium text-primary transition-opacity hover:opacity-80"
        >
          View all notifications
        </button>
      </div>
    </Popover>
  )
}

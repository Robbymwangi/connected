import { Bell, Menu, Moon, Search, Sun } from 'lucide-react'
import { useRef, useState } from 'react'
import { syncState } from '../fixtures/sync'
import { useConnectivity } from '../lib/connectivity'
import { useTheme } from '../lib/theme'
import { NotificationsPopup } from './NotificationsPopup'
import { SyncStatusIndicator } from './SyncStatusIndicator'

type TopBarProps = {
  menuPinned: boolean
  onMenuHover: () => void
  onMenuClick: () => void
}

export function TopBar({ menuPinned, onMenuHover, onMenuClick }: TopBarProps) {
  const { theme, toggleTheme } = useTheme()
  const { isOnline, toggleOverride } = useConnectivity()
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const notificationsTrigger = useRef<HTMLButtonElement>(null)

  return (
    <header className="topbar-glass sticky top-0 z-30 flex h-16 items-stretch border-b border-border/60 backdrop-blur-md transition-colors">
      {/* Hover peeks the sidebar; click pins it. */}
      <button
        type="button"
        onMouseEnter={onMenuHover}
        onClick={onMenuClick}
        aria-label={menuPinned ? 'Close navigation' : 'Open navigation'}
        aria-expanded={menuPinned}
        className="relative flex shrink-0 items-center justify-center border-r border-border px-4 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        <Menu className="size-5" />
        {menuPinned && (
          <span className="absolute right-2.5 bottom-2.5 size-1.5 rounded-full bg-primary" />
        )}
      </button>

      <div className="flex shrink-0 items-center gap-2.5 border-r border-border px-4 sm:px-5">
        <div className="flex size-9 items-center justify-center rounded-xl bg-primary text-sm font-bold text-primary-foreground shadow-sm ring-1 ring-primary-foreground/20">
          C
        </div>
      </div>

      <div className="flex min-w-0 flex-1 items-center justify-center px-4 lg:px-6">
        <div className="relative hidden w-full max-w-sm items-center md:flex">
          <span className="pointer-events-none absolute left-3 text-muted-foreground">
            <Search className="size-4" />
          </span>
          <input
            type="search"
            placeholder="Search"
            className="w-full rounded-xl border border-border bg-muted/60 py-2 pr-9 pl-9 text-center text-sm text-foreground transition-all placeholder:text-muted-foreground focus:border-primary/40 focus:ring-2 focus:ring-primary/30 focus:outline-none"
          />
        </div>
      </div>

      <div className="flex items-center divide-x divide-border border-l border-border">
        <button
          type="button"
          onClick={toggleTheme}
          aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
          className="flex h-full items-center justify-center px-4 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        >
          {theme === 'dark' ? <Sun className="size-5" /> : <Moon className="size-5" />}
        </button>

        <div className="relative flex h-full items-center">
          <button
            ref={notificationsTrigger}
            type="button"
            onClick={() => setNotificationsOpen((v) => !v)}
            aria-expanded={notificationsOpen}
            aria-label="Notifications"
            className="flex h-full items-center gap-2 px-4 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            <Bell className="size-5" />
            <span className="hidden text-sm font-medium md:block">Notifications</span>
          </button>
          <NotificationsPopup
            open={notificationsOpen}
            onClose={() => setNotificationsOpen(false)}
            triggerRef={notificationsTrigger}
          />
        </div>

        <div className="flex items-center px-4 lg:px-5">
          <SyncStatusIndicator
            isOnline={isOnline}
            pendingCount={syncState.pendingCount}
            lastSyncedAt={syncState.lastSyncedAt}
            onToggleOverride={toggleOverride}
          />
        </div>
      </div>
    </header>
  )
}

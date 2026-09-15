import { Menu, Moon, Search, Sun } from 'lucide-react'
import { syncState } from '../fixtures/sync'
import { currentUser } from '../fixtures/user'
import { useConnectivity } from '../lib/connectivity'
import { useTheme } from '../lib/theme'
import { formatLongDate, greetingFor } from '../lib/time'
import { SyncStatusIndicator } from './SyncStatusIndicator'

type TopBarProps = {
  onOpenMenu: () => void
}

export function TopBar({ onOpenMenu }: TopBarProps) {
  const { theme, toggleTheme } = useTheme()
  const { isOnline, toggleOverride } = useConnectivity()
  const now = new Date()

  return (
    <header className="topbar-glass sticky top-0 z-30 flex h-16 items-stretch rounded-t-2xl border-b border-border/60 backdrop-blur-md transition-colors">
      <button
        type="button"
        onClick={onOpenMenu}
        aria-label="Open navigation"
        className="flex shrink-0 items-center justify-center border-r border-border px-4 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        <Menu className="size-5" />
      </button>

      <div className="flex shrink-0 items-center gap-2.5 border-r border-border px-4 sm:px-5">
        <div className="flex size-9 items-center justify-center rounded-xl bg-primary text-sm font-bold text-primary-foreground shadow-sm ring-1 ring-primary-foreground/20">
          C
        </div>
      </div>

      <div className="flex shrink-0 items-center px-5 lg:px-7">
        <div>
          <h1 className="text-sm leading-tight font-semibold tracking-tight text-foreground lg:text-base">
            {greetingFor(now)}, {currentUser.firstName}
          </h1>
          <p className="hidden text-[11px] font-medium text-muted-foreground sm:block">
            {formatLongDate(now)}
          </p>
        </div>
      </div>

      <div className="flex min-w-0 flex-1 items-center px-4 lg:px-6">
        <div className="relative hidden w-full max-w-sm items-center md:flex">
          <span className="pointer-events-none absolute left-3 text-muted-foreground">
            <Search className="size-4" />
          </span>
          <input
            type="search"
            placeholder="Search"
            className="w-full rounded-xl border border-border bg-muted/60 py-2 pr-4 pl-9 text-center text-sm text-foreground transition-all placeholder:text-muted-foreground focus:border-primary/40 focus:ring-2 focus:ring-primary/30 focus:outline-none"
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

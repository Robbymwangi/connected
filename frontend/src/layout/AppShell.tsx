import { useState, type ReactNode } from 'react'
import './glass.css'
import type { NavId } from './navigation'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'

type AppShellProps = {
  active: NavId
  onNavigate: (id: NavId) => void
  children: ReactNode
}

/* Outer shell surface, the drawer, and the inner window. The inner window is the
   single scroll context: the top bar is sticky inside it, so page content scrolls
   behind the bar's glass. */
export function AppShell({ active, onNavigate, children }: AppShellProps) {
  const [drawerOpen, setDrawerOpen] = useState(false)

  return (
    <div className="flex h-screen overflow-hidden bg-shell transition-colors duration-300">
      <Sidebar
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        active={active}
        onNavigate={onNavigate}
      />

      <div className="m-2 min-w-0 flex-1 overflow-y-auto rounded-2xl bg-background shadow-xl transition-colors duration-300">
        <TopBar onOpenMenu={() => setDrawerOpen(true)} />
        <main>{children}</main>
      </div>
    </div>
  )
}

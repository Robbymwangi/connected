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

/* Outer shell surface, the drawer, and the scrolling window. The window is the single
   scroll context: the top bar is sticky inside it, so page content scrolls behind
   the bar's glass.

   The sidebar opens two ways. Hovering the left edge or the hamburger peeks it, and
   it retracts when the pointer leaves. Clicking the hamburger pins it until closed.
   Touch devices have no hover, so pinning is the path that must always work. */
export function AppShell({ active, onNavigate, children }: AppShellProps) {
  const [pinned, setPinned] = useState(false)
  const [hovering, setHovering] = useState(false)
  const open = pinned || hovering

  const close = () => {
    setPinned(false)
    setHovering(false)
  }

  return (
    <div className="flex h-screen overflow-hidden bg-shell transition-colors duration-300">
      <Sidebar
        open={open}
        pinned={pinned}
        onClose={close}
        onHoverEnd={() => {
          if (!pinned) setHovering(false)
        }}
        active={active}
        onNavigate={onNavigate}
      />

      {!open && (
        <div
          aria-hidden
          className="fixed inset-y-0 left-0 z-30 w-3"
          onMouseEnter={() => setHovering(true)}
        />
      )}

      <div className="min-w-0 flex-1 overflow-y-auto bg-background transition-colors duration-300">
        <TopBar
          menuPinned={pinned}
          onMenuHover={() => setHovering(true)}
          onMenuClick={() => {
            setPinned((p) => !p)
            setHovering(false)
          }}
        />
        <main>{children}</main>
      </div>
    </div>
  )
}

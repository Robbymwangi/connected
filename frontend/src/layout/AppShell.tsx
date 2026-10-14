import { useEffect, useRef, useState, type ReactNode } from 'react'
import './glass.css'
import type { NavId } from './navigation'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { UpdateReady } from './UpdateReady'

type AppShellProps = {
  active: NavId
  onNavigate: (id: NavId) => void
  /* How many conflicts need this person, for the sync line in the top bar. */
  conflictsNeedingYou: number
  children: ReactNode
}

/* Outer shell surface, the drawer, and the scrolling window. The window is the single
   scroll context: the top bar is sticky inside it, so page content scrolls behind
   the bar's glass.

   The sidebar opens two ways. Resting the pointer on the hamburger for a moment peeks
   it, and it retracts when the pointer leaves. Clicking the hamburger pins it until
   closed. Only the hamburger peeks it, and only after PEEK_DELAY_MS, so a pointer
   passing over it, or drifting to the left edge (to the marking grid's names, say),
   does not slide the drawer out. Touch devices have no hover, so pinning is the path
   that must always work. */
const PEEK_DELAY_MS = 150

export function AppShell({ active, onNavigate, conflictsNeedingYou, children }: AppShellProps) {
  const [pinned, setPinned] = useState(false)
  const [hovering, setHovering] = useState(false)
  const open = pinned || hovering
  const peekTimer = useRef<number | undefined>(undefined)

  const cancelPeek = () => window.clearTimeout(peekTimer.current)
  const startPeek = () => {
    cancelPeek()
    peekTimer.current = window.setTimeout(() => setHovering(true), PEEK_DELAY_MS)
  }
  useEffect(() => cancelPeek, [])

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

      <div className="min-w-0 flex-1 overflow-y-auto bg-background transition-colors duration-300">
        <UpdateReady />
        <TopBar
          conflictsNeedingYou={conflictsNeedingYou}
          menuPinned={pinned}
          onMenuHover={startPeek}
          onMenuHoverEnd={cancelPeek}
          onMenuClick={() => {
            cancelPeek()
            setPinned((p) => !p)
            setHovering(false)
          }}
        />
        <main>{children}</main>
      </div>
    </div>
  )
}

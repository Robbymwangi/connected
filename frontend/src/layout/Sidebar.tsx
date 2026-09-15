import { Ellipsis, LogOut, X } from 'lucide-react'
import { Button } from '../components/Button'
import { NavItem } from '../components/NavItem'
import { currentUser } from '../fixtures/user'
import { NAV_MAIN, NAV_TOOLS, type NavId } from './navigation'

type SidebarProps = {
  open: boolean
  onClose: () => void
  active: NavId
  onNavigate: (id: NavId) => void
}

/* The sidebar is a drawer at every breakpoint: a scrim over the page and a panel that
   slides in from the left. Choosing a destination closes it. */
export function Sidebar({ open, onClose, active, onNavigate }: SidebarProps) {
  return (
    <>
      <div
        aria-hidden
        onClick={onClose}
        className={`fixed inset-0 z-40 bg-overlay backdrop-blur-sm transition-opacity duration-300 ${
          open ? 'opacity-100' : 'pointer-events-none opacity-0'
        }`}
      />

      <aside
        aria-label="Main navigation"
        aria-hidden={!open}
        className={`fixed inset-y-0 left-0 z-50 flex w-[280px] flex-col shadow-2xl backdrop-blur-xl transition-transform duration-300 ease-in-out ${
          open ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="absolute top-4 right-4 z-50">
          <Button
            onClick={onClose}
            aria-label="Close navigation"
            className="rounded-full bg-card/80 shadow-sm backdrop-blur-md"
          >
            <X className="size-5" />
          </Button>
        </div>

        <div className="sidebar-drawer-glass flex h-full flex-col border-r border-border/50 transition-colors">
          <div className="flex shrink-0 items-center gap-3 border-b border-border px-4 py-5">
            <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-sm font-bold text-primary-foreground shadow-sm">
              C
            </div>
            <span className="font-display text-lg font-semibold tracking-tight whitespace-nowrap text-foreground">
              ConnectED
            </span>
          </div>

          <nav className="flex-1 overflow-y-auto p-3">
            <div className="sidebar-nav-glass flex min-h-full flex-col gap-0.5 rounded-2xl p-2">
              {[...NAV_MAIN, ...NAV_TOOLS].map(({ id, label, icon }) => (
                <NavItem
                  key={id}
                  icon={icon}
                  label={label}
                  active={active === id}
                  onClick={() => {
                    onNavigate(id)
                    onClose()
                  }}
                />
              ))}
            </div>
          </nav>

          <div className="shrink-0 border-t border-border p-3">
            <div className="sidebar-footer-glass rounded-2xl p-3">
              <div className="mb-4 flex items-center gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-sm font-semibold text-primary shadow-sm">
                  {currentUser.initials}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm leading-tight font-semibold text-foreground">
                    {currentUser.fullName}
                  </p>
                  <p className="text-xs text-muted-foreground">{currentUser.role}</p>
                </div>
                <Button aria-label="Account options">
                  <Ellipsis className="size-5" />
                </Button>
              </div>
              <Button
                variant="danger"
                className="flex w-full items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium"
              >
                <LogOut className="size-4" />
                <span>Log Out</span>
              </Button>
            </div>
          </div>
        </div>
      </aside>
    </>
  )
}

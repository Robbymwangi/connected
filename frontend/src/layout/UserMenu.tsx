import { Settings, ShieldCheck, UserRound, type LucideIcon } from 'lucide-react'
import type { RefObject } from 'react'
import { Popover } from '../components/Popover'
import { currentUser } from '../fixtures/user'

const ITEMS: { icon: LucideIcon; label: string }[] = [
  { icon: UserRound, label: 'My Profile' },
  { icon: Settings, label: 'Preferences' },
  { icon: ShieldCheck, label: 'Privacy and Security' },
]

type UserMenuProps = {
  open: boolean
  onClose: () => void
  triggerRef: RefObject<HTMLElement | null>
}

/* Entries are inert until those screens exist. */
export function UserMenu({ open, onClose, triggerRef }: UserMenuProps) {
  return (
    <Popover
      open={open}
      onClose={onClose}
      anchor="bottom-left"
      triggerRef={triggerRef}
      className="w-52 bg-card"
    >
      <div className="border-b border-border/60 px-4 py-3">
        <p className="text-sm font-semibold text-foreground">{currentUser.fullName}</p>
        <p className="text-xs text-muted-foreground">{currentUser.email}</p>
      </div>
      <div className="py-1.5">
        {ITEMS.map(({ icon: Icon, label }) => (
          <button
            key={label}
            type="button"
            onClick={onClose}
            className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
          >
            <Icon className="size-4" />
            <span>{label}</span>
          </button>
        ))}
      </div>
    </Popover>
  )
}

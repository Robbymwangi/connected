import type { LucideIcon } from 'lucide-react'

type NavItemProps = {
  icon: LucideIcon
  label: string
  active: boolean
  onClick: () => void
}

export function NavItem({ icon: Icon, label, active, onClick }: NavItemProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-current={active ? 'page' : undefined}
      className={`flex w-full items-center gap-3 rounded-xl py-2.5 pl-4 text-left text-sm font-medium transition-all ${
        active
          ? 'bg-primary/10 text-primary shadow-sm'
          : 'text-muted-foreground hover:bg-muted hover:text-foreground'
      }`}
    >
      <Icon className="size-5 shrink-0" />
      {label}
    </button>
  )
}

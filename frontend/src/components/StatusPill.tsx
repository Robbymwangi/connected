import type { ReactNode } from 'react'

export type StatusTone = 'success' | 'info' | 'neutral' | 'warning' | 'danger' | 'primary'

const TONE_CLASSES: Record<StatusTone, string> = {
  success: 'border-success/20 bg-success/10 text-success',
  info: 'border-info/20 bg-info/10 text-info',
  neutral: 'border-border bg-muted text-muted-foreground',
  warning: 'border-warning/20 bg-warning/10 text-warning',
  danger: 'border-danger/20 bg-danger/10 text-danger',
  primary: 'border-primary/20 bg-primary/10 text-primary',
}

/* md is the top-bar pill; sm is the compact uppercase badge used in lists. */
const SIZE_CLASSES = {
  md: 'gap-2 px-3 py-1.5 text-xs font-semibold',
  sm: 'gap-1.5 px-2 py-0.5 text-[10px] font-bold tracking-wide uppercase',
}

type StatusPillProps = {
  tone: StatusTone
  size?: keyof typeof SIZE_CLASSES
  icon?: ReactNode
  children: ReactNode
  className?: string
  'aria-label'?: string
}

export function StatusPill({ tone, size = 'md', icon, children, className = '', ...aria }: StatusPillProps) {
  return (
    <span
      {...aria}
      className={`inline-flex items-center rounded-full border transition-all ${SIZE_CLASSES[size]} ${TONE_CLASSES[tone]} ${className}`}
    >
      {icon}
      {children}
    </span>
  )
}

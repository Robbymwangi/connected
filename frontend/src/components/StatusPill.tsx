import type { ReactNode } from 'react'

export type StatusTone = 'success' | 'neutral' | 'warning' | 'danger'

const TONE_CLASSES: Record<StatusTone, string> = {
  success: 'border-success/20 bg-success/10 text-success',
  neutral: 'border-border bg-muted text-muted-foreground',
  warning: 'border-warning/20 bg-warning/10 text-warning',
  danger: 'border-danger/20 bg-danger/10 text-danger',
}

type StatusPillProps = {
  tone: StatusTone
  icon?: ReactNode
  children: ReactNode
  className?: string
}

export function StatusPill({ tone, icon, children, className = '' }: StatusPillProps) {
  return (
    <span
      className={`inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all ${TONE_CLASSES[tone]} ${className}`}
    >
      {icon}
      {children}
    </span>
  )
}

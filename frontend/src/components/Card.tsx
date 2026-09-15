import { ArrowUpRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from './Button'

type CardProps = {
  title: string
  /* Right-hand slot in the header, before the expand control. */
  action?: ReactNode
  /* Dashboard cards are static; the one way out is the expand control, which
     leads to the section that owns the card's data. */
  onExpand?: () => void
  expandLabel?: string
  children: ReactNode
  className?: string
}

export function Card({
  title,
  action,
  onExpand,
  expandLabel = 'Open',
  children,
  className = '',
}: CardProps) {
  return (
    <section
      className={`flex flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-sm backdrop-blur-md transition-colors ${className}`}
    >
      <div className="flex shrink-0 items-center justify-between border-b border-border/50 px-5 py-4">
        <h2 className="font-display text-base font-semibold tracking-wide text-foreground">
          {title}
        </h2>
        <div className="flex items-center gap-2">
          {action}
          {onExpand && (
            <Button onClick={onExpand} aria-label={expandLabel} className="p-1">
              <ArrowUpRight className="size-4" />
            </Button>
          )}
        </div>
      </div>
      <div className="flex flex-1 flex-col">{children}</div>
    </section>
  )
}

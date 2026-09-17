import type { ReactNode } from 'react'

type PanelProps = {
  title: string
  aside?: ReactNode
  children: ReactNode
  className?: string
}

/* A titled card section, the building block of the detail views. */
export function Panel({ title, aside, children, className = '' }: PanelProps) {
  return (
    <section className={`overflow-hidden rounded-2xl border border-border bg-card shadow-sm ${className}`}>
      <div className="flex items-center justify-between border-b border-border/50 px-5 py-4">
        <h2 className="text-sm font-semibold text-foreground">{title}</h2>
        {aside && <span className="text-xs text-muted-foreground">{aside}</span>}
      </div>
      {children}
    </section>
  )
}

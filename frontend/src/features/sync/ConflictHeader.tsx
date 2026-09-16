import type { ReactNode } from 'react'

type ConflictHeaderProps = {
  student: string
  criterion: string
  assessment: string
  children?: ReactNode
}

export function ConflictHeader({ student, criterion, assessment, children }: ConflictHeaderProps) {
  return (
    <div className="flex items-start justify-between gap-3 border-b border-border/50 px-4 pt-4 pb-3">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm leading-snug font-semibold text-foreground">{student}</span>
          <span className="text-sm text-muted-foreground/50">·</span>
          <span className="text-sm leading-snug font-medium text-foreground/80">{criterion}</span>
        </div>
        <p className="mt-1 truncate text-xs text-muted-foreground">{assessment}</p>
      </div>
      {children}
    </div>
  )
}

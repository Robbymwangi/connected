import { ChevronRight } from 'lucide-react'
import type { SchoolClass } from '../../fixtures/classes'
import { count } from '../../lib/time'

type ClassCardProps = {
  cls: SchoolClass
  onOpen: () => void
}

export function ClassCard({ cls, onOpen }: ClassCardProps) {
  return (
    <button
      type="button"
      onClick={onOpen}
      className="group flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 text-left shadow-sm transition-all hover:border-border/80 hover:bg-muted/20"
    >
      <div className="flex items-start justify-between">
        <h3 className="font-display text-xl font-black text-foreground">Stream {cls.stream}</h3>
        <div className="flex size-10 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-sm font-black text-primary">
          {cls.stream}
        </div>
      </div>
      <div className="flex items-center gap-3 text-xs text-muted-foreground">
        <span>{count(cls.enrolment, 'student')}</span>
        <span>{count(cls.assessments, 'assessment')}</span>
      </div>
      <div className="flex items-center justify-between border-t border-border/50 pt-3">
        <span className="truncate text-xs font-medium text-muted-foreground">{cls.teacher}</span>
        <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground" />
      </div>
    </button>
  )
}

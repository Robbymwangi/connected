import { ChevronRight } from 'lucide-react'
import type { Teacher } from '../../fixtures/teachers'

type TeacherCardProps = {
  teacher: Teacher
  onOpen: () => void
}

export function TeacherCard({ teacher: t, onOpen }: TeacherCardProps) {
  const streams = Object.keys(t.subjectsByStream)
  const subjects = [...new Set(Object.values(t.subjectsByStream).flat())]
  return (
    <button
      type="button"
      onClick={onOpen}
      className="group flex items-center gap-4 rounded-2xl border border-border bg-card px-5 py-4 text-left shadow-sm transition-all hover:bg-muted/20"
    >
      <div className="flex size-11 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-sm font-bold text-primary">
        {t.initials}
      </div>
      <div className="min-w-0 flex-1">
        <h3 className="truncate text-base font-bold text-foreground">{t.name}</h3>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {subjects.join(', ')} · {streams.length} stream{streams.length === 1 ? '' : 's'}
          {t.homeStream && ` · Class teacher, ${t.homeStream}`}
        </p>
      </div>
      <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground" />
    </button>
  )
}

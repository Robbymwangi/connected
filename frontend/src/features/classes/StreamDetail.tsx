import { ChevronRight } from 'lucide-react'
import type { SessionStore } from '../../app/useSessionStore'
import { BackNav } from '../../components/BackNav'
import type { SchoolClass } from '../../fixtures/classes'
import { initials, rosterFor } from '../../fixtures/students'
import { teachersOfStream } from '../../fixtures/teachers'
import { AssessmentRow } from '../assessments/AssessmentRow'
import { Panel } from './Panel'

type StreamDetailProps = {
  cls: SchoolClass
  year: number
  store: SessionStore
  onBack: () => void
  onOpenStudent: (studentId: string) => void
  onOpenTeacher: (teacherId: string) => void
  onOpenGrid: (assessmentId: string) => void
  onOpenReport: (assessmentId: string) => void
}

export function StreamDetail({ cls, year, store, onBack, onOpenStudent, onOpenTeacher, onOpenGrid, onOpenReport }: StreamDetailProps) {
  const roster = rosterFor(cls.id)
  const assessments = store.assessments.filter((a) => a.stream === cls.stream && a.year === year)
  const teachers = teachersOfStream(cls.stream)

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <BackNav onBack={onBack} items={[{ label: 'Classes', onClick: onBack }, { label: `Stream ${cls.stream}` }]} />
      <div className="mb-5">
        <h1 className="font-display text-2xl font-bold tracking-tight text-foreground">Stream {cls.stream}</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {cls.grade} · {cls.enrolment} students · class teacher {cls.teacher}
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title="Student roster" aside={`${roster.length} students`} className="lg:row-span-2">
          <div className="max-h-[520px] divide-y divide-border/40 overflow-y-auto">
            {roster.map((s) => (
              <button
                key={s.id}
                type="button"
                onClick={() => onOpenStudent(s.id)}
                className="group flex w-full items-center gap-3 px-5 py-2.5 text-left transition-colors hover:bg-muted/30"
              >
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-[10px] font-bold text-primary">
                  {initials(s.name)}
                </span>
                <span className="flex-1 truncate text-sm font-medium text-foreground">{s.name}</span>
                <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground" />
              </button>
            ))}
          </div>
        </Panel>

        <Panel title="Assessments" aside={`${assessments.length} in ${year}`}>
          <div className="flex flex-col gap-2 p-3">
            {assessments.length === 0 && (
              <p className="py-6 text-center text-sm text-muted-foreground">No assessments for {year}.</p>
            )}
            {assessments.map((a) => (
              <AssessmentRow key={a.id} assessment={a} compact onOpenGrid={() => onOpenGrid(a.id)} onOpenReport={() => onOpenReport(a.id)} />
            ))}
          </div>
        </Panel>

        <Panel title="Teachers" aside={`${teachers.length}`}>
          <div className="divide-y divide-border/40">
            {teachers.map((t) => (
              <button
                key={t.id}
                type="button"
                onClick={() => onOpenTeacher(t.id)}
                className="group flex w-full items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-muted/30"
              >
                <span className="flex size-8 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-[11px] font-bold text-primary">
                  {t.initials}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-foreground">{t.name}</span>
                  <span className="block text-xs text-muted-foreground">
                    {t.subjectsByStream[cls.stream]?.join(', ')}
                    {t.homeStream === cls.stream && ' · class teacher'}
                  </span>
                </span>
                <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground" />
              </button>
            ))}
          </div>
        </Panel>
      </div>
    </div>
  )
}

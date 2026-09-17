import { Mail, Phone } from 'lucide-react'
import { BackNav } from '../../components/BackNav'
import { StatusPill } from '../../components/StatusPill'
import { classes } from '../../fixtures/classes'
import type { Teacher } from '../../fixtures/teachers'
import { Panel } from './Panel'

type TeacherDetailProps = {
  teacher: Teacher
  onBack: () => void
  onOpenClass: (classId: string) => void
}

export function TeacherDetail({ teacher: t, onBack, onOpenClass }: TeacherDetailProps) {
  const streams = classes.filter((c) => c.stream in t.subjectsByStream)

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <BackNav onBack={onBack} items={[{ label: 'Classes', onClick: onBack }, { label: 'Teachers' }, { label: t.name }]} />
      <div className="mb-5 flex items-center gap-4">
        <div className="flex size-14 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-lg font-bold text-primary">
          {t.initials}
        </div>
        <div>
          <h1 className="font-display text-2xl font-bold tracking-tight text-foreground">{t.name}</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {t.homeStream ? `Class teacher, Stream ${t.homeStream}` : 'Subject teacher'}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title="Teaching responsibilities">
          <div className="divide-y divide-border/40">
            {streams.map((c) => (
              <button
                key={c.id}
                type="button"
                onClick={() => onOpenClass(c.id)}
                className="flex w-full items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-muted/30"
              >
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/10 text-xs font-black text-primary">
                  {c.stream}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-medium text-foreground">{c.grade} · Stream {c.stream}</span>
                  <span className="block text-xs text-muted-foreground">{t.subjectsByStream[c.stream]?.join(', ')}</span>
                </span>
                {t.homeStream === c.stream && (
                  <StatusPill tone="primary" size="sm">Class teacher</StatusPill>
                )}
              </button>
            ))}
          </div>
        </Panel>

        <Panel title="Contact">
          <div className="flex flex-col gap-3 px-5 py-4 text-sm">
            <p className="flex items-center gap-2 text-foreground">
              <Mail className="size-4 text-muted-foreground" /> {t.email}
            </p>
            <p className="flex items-center gap-2 text-foreground">
              <Phone className="size-4 text-muted-foreground" /> {t.phone}
            </p>
          </div>
        </Panel>
      </div>
    </div>
  )
}

import type { SessionStore } from '../../app/useSessionStore'
import { BackNav } from '../../components/BackNav'
import { LineChart } from '../../components/charts'
import { LevelBadge } from '../../components/LevelBadge'
import type { SchoolClass } from '../../fixtures/classes'
import { results as records } from '../../fixtures/results'
import { initials, type Student } from '../../fixtures/students'
import { resultsForStudent, trendBySubject } from '../../lib/results'
import { formatLongDate, parseLocalDate } from '../../lib/time'
import { Panel } from './Panel'

type StudentProfileProps = {
  student: Student
  cls: SchoolClass
  store: SessionStore
  onBack: () => void
  onBackToList: () => void
}

/* Reached from a stream, never from the sidebar: the third level the two-level
   navigation cap allows contextually. Results come from the live grid where a
   complete row exists, otherwise from the records on file. */
export function StudentProfile({ student, cls, store, onBack, onBackToList }: StudentProfileProps) {
  const results = resultsForStudent(student.id, cls.stream, store.assessments, store.marks, records)
  const trends = trendBySubject(results)

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <BackNav
        onBack={onBack}
        items={[{ label: 'Classes', onClick: onBackToList }, { label: `Stream ${cls.stream}`, onClick: onBack }, { label: student.name }]}
      />
      <div className="mb-5 flex items-center gap-4">
        <div className="flex size-14 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-lg font-bold text-primary">
          {initials(student.name)}
        </div>
        <div className="min-w-0 flex-1">
          <h1 className="font-display text-2xl font-bold tracking-tight text-foreground">{student.name}</h1>
          <p className="mt-1.5 text-xs text-muted-foreground">
            Born {formatLongDate(parseLocalDate(student.dob))} · {student.gender === 'F' ? 'Female' : 'Male'} · {cls.grade} · Stream {cls.stream}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title="Assessment results" aside={`${results.length} entries`} className="lg:col-span-2">
          {results.length === 0 ? (
            <p className="px-5 py-8 text-center text-sm text-muted-foreground">No assessment data yet.</p>
          ) : (
            <div className="divide-y divide-border/40">
              {results.map((r) => (
                <div key={r.assessmentId} className="flex items-center gap-4 px-5 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-foreground">
                      {r.subject}: {r.name}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {r.term}
                      {r.source === 'grid' && <span className="ml-2 text-warning">from the marking grid</span>}
                    </p>
                  </div>
                  <span className="text-sm font-bold text-foreground tabular">
                    {r.total}/{r.max}
                  </span>
                  <span className="w-10 text-right text-xs text-muted-foreground tabular">{Math.round((r.total / r.max) * 100)}%</span>
                  <LevelBadge level={r.level} />
                </div>
              ))}
            </div>
          )}
        </Panel>

        {Object.entries(trends).map(([subject, points]) =>
          points.length < 2 ? null : (
            <Panel key={subject} title={`${subject} trend`}>
              <div className="px-3 py-3">
                <LineChart
                  label={`${student.name}'s ${subject} scores across assessments, as a percentage`}
                  categories={points.map((p) => p.label)}
                  series={[{ id: subject, label: subject, color: 'primary', values: points.map((p) => p.pct) }]}
                  max={100}
                  format={(v) => `${v}%`}
                  height={150}
                />
              </div>
            </Panel>
          ),
        )}

        <Panel title="Generated reports">
          <p className="px-5 py-6 text-center text-sm text-muted-foreground">No reports generated yet for this student.</p>
        </Panel>
      </div>
    </div>
  )
}

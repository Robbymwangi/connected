import { ChevronRight } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { BackNav } from '../../components/BackNav'
import type { Crumb } from '../../components/Breadcrumb'
import { CompletionBar } from '../../components/CompletionBar'
import type { Assessment } from '../../fixtures/assessments'
import { classes, grades } from '../../fixtures/classes'
import { AssessmentRow } from './AssessmentRow'

type BrowseTreeProps = {
  assessments: Assessment[]
  onOpenGrid: (id: string) => void
  onOpenReport: (id: string) => void
}

/* Grade, then stream, then that stream's assessments. This is a scoped filter with
   a breadcrumb, inside the Assessments level; the grid beneath is the second and
   last navigation level. */
export function BrowseTree({ assessments, onOpenGrid, onOpenReport }: BrowseTreeProps) {
  const [grade, setGrade] = useState<string | null>(null)
  const [stream, setStream] = useState<string | null>(null)

  const crumbs: Crumb[] = [
    { label: 'Assessments', onClick: () => { setGrade(null); setStream(null) } },
    ...(grade ? [{ label: grade, onClick: () => setStream(null) }] : []),
    ...(stream ? [{ label: `Stream ${stream}` }] : []),
  ]

  const inStream = (s: string) => assessments.filter((a) => a.stream === s)
  const inGrade = (g: string) => classes.filter((c) => c.grade === g)

  return (
    <div>
      {grade && (
        <BackNav
          onBack={() => (stream ? setStream(null) : setGrade(null))}
          items={crumbs}
        />
      )}

      {!grade && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {grades.map((g) => {
            const count = inGrade(g).reduce((n, c) => n + inStream(c.stream).length, 0)
            return (
              <TreeCard key={g} onClick={() => setGrade(g)}>
                <p className="text-base font-bold text-foreground">{g}</p>
                <p className="mt-1 text-xs text-muted-foreground">{count} assessments</p>
                <ChevronRight className="mt-3 size-4 text-muted-foreground transition-colors group-hover:text-foreground" />
              </TreeCard>
            )
          })}
        </div>
      )}

      {grade && !stream && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {inGrade(grade).map((cls) => {
            const items = inStream(cls.stream)
            const completion =
              items.length > 0
                ? Math.round((items.reduce((s, a) => s + a.entered / a.total, 0) / items.length) * 100)
                : 0
            return (
              <TreeCard key={cls.id} onClick={() => setStream(cls.stream)}>
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-base font-bold text-foreground">Stream {cls.stream}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">{cls.teacher}</p>
                  </div>
                  <ChevronRight className="size-4 text-muted-foreground transition-colors group-hover:text-foreground" />
                </div>
                <div className="mt-3">
                  <CompletionBar entered={completion} total={100} />
                </div>
              </TreeCard>
            )
          })}
        </div>
      )}

      {grade && stream && (
        <div className="flex flex-col gap-2">
          {inStream(stream).map((a) => (
            <AssessmentRow
              key={a.id}
              assessment={a}
              compact
              onOpenGrid={() => onOpenGrid(a.id)}
              onOpenReport={() => onOpenReport(a.id)}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function TreeCard({ onClick, children }: { onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="group rounded-2xl border border-border bg-card px-5 py-4 text-left shadow-sm transition-colors hover:bg-muted/30"
    >
      {children}
    </button>
  )
}

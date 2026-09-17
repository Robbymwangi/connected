import { FileDown } from 'lucide-react'
import type { SessionStore } from '../../app/useSessionStore'
import { BackNav } from '../../components/BackNav'
import { LevelBadge } from '../../components/LevelBadge'
import { StatusPill } from '../../components/StatusPill'
import type { Assessment } from '../../fixtures/assessments'
import { results } from '../../fixtures/results'
import { outcomesFor, PASS_MARK_PCT } from '../../lib/analytics'
import { performanceLevel } from '../../lib/grading'
import { scopeLabel } from '../../lib/reportScopes'
import { count } from '../../lib/time'
import { Panel } from '../classes/Panel'
import { BarList } from '../reports/BarList'
import { ReportKpiTile } from '../reports/ReportKpiTile'
import { useReport } from '../reports/useReport'
import { STATUS_META } from './statusMeta'

type AssessmentReportProps = {
  assessment: Assessment
  store: SessionStore
  onBack: () => void
  onOpenStudent: (classId: string, studentId: string) => void
}

const fmtPct = (v: number | null) => (v === null ? '–' : `${Math.round(v)}%`)

/* The report for one assessment: the same analytics as Reports, narrowed to this
   assessment, plus every student's result. The PDF is generated on the server into
   S3, so the download is a later feature, not a stub here. */
export function AssessmentReport({ assessment: a, store, onBack, onOpenStudent }: AssessmentReportProps) {
  const report = useReport({ stream: a.stream, subject: a.subject }, { term: a.term, assessment: a.name }, store)
  const s = report?.summary
  const outcomes = report ? outcomesFor(a, { assessments: store.assessments, marks: store.marks, records: results, roster: report.roster }) : []
  const label = scopeLabel({ stream: a.stream, subject: a.subject })

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <BackNav onBack={onBack} items={[{ label: 'Assessments', onClick: onBack }, { label: `${a.subject} · ${a.stream}` }, { label: 'Report' }]} />
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl leading-tight font-bold tracking-tight text-foreground">
            {a.subject}: {a.stream}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {a.name} · {a.term}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <StatusPill tone={STATUS_META[a.status].tone} size="sm">{STATUS_META[a.status].label}</StatusPill>
          <button
            type="button"
            disabled
            title="PDF reports are generated on the server once the API is connected"
            className="flex cursor-not-allowed items-center gap-1.5 rounded-xl border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground opacity-50"
          >
            <FileDown className="size-4" /> Download PDF
          </button>
        </div>
      </div>

      {!report || !s || s.scored + s.absent === 0 ? (
        <p className="py-16 text-center text-sm text-muted-foreground">No marks for this assessment yet.</p>
      ) : (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <ReportKpiTile label="Pass rate" primaryLabel={label} primary={{ value: fmtPct(s.passRate), n: s.scored, sub: `pass mark ${PASS_MARK_PCT}%` }} />
            <ReportKpiTile label="Mean score" primaryLabel={label} primary={{ value: fmtPct(s.meanPct), n: s.scored }} />
            <ReportKpiTile label="Score spread" primaryLabel={label} primary={{ value: s.spread ? `${Math.round(s.spread.min)}–${Math.round(s.spread.max)}` : '–', sub: s.spread ? `IQR ${s.spread.iqr.toFixed(1)} pts` : undefined, n: s.scored }} />
            <ReportKpiTile label="Entry completeness" warn={s.completeness !== null && s.completeness < 100} primaryLabel={label} primary={{ value: fmtPct(s.completeness), sub: `${s.scored + s.absent} of ${s.scored + s.absent + s.missing}`, n: s.scored + s.absent + s.missing }} />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {/* Criterion detail exists only where a marking grid does; for a
                record-only assessment the panel is dropped and student results
                take the full width rather than sit beside an empty box. */}
            {report.criteria.length > 0 && (
              <Panel title="Criterion breakdown" aside="average share achieved">
                <div className="px-5 py-4">
                  <BarList rows={[...report.criteria].sort((x, y) => y.pct - x.pct).map((c) => ({ label: c.name, pct: c.pct, detail: `n=${c.n}` }))} reference={PASS_MARK_PCT} />
                </div>
              </Panel>
            )}

            <Panel title="Student results" aside={count(report.roster.length, 'student')} className={report.criteria.length === 0 ? 'lg:col-span-2' : undefined}>
              <div className="max-h-[420px] divide-y divide-border/40 overflow-y-auto">
                {report.roster.map((student) => {
                  const o = outcomes.find((x) => x.studentId === student.id)
                  return (
                    <button key={student.id} type="button" onClick={() => onOpenStudent(student.classId, student.id)} className="flex w-full items-center gap-4 px-5 py-2.5 text-left transition-colors hover:bg-muted/30">
                      <span className="flex-1 truncate text-sm font-medium text-foreground">{student.name}</span>
                      {o?.status === 'scored' ? (
                        <>
                          <span className="text-sm font-bold text-foreground tabular">{o.total}/{o.max}</span>
                          <span className="w-10 text-right text-xs text-muted-foreground tabular">{Math.round(o.pct)}%</span>
                          <LevelBadge level={performanceLevel(o.total, o.max)} />
                        </>
                      ) : (
                        <span className="text-xs text-muted-foreground">{o?.status === 'absent' ? 'Absent' : 'Not entered'}</span>
                      )}
                    </button>
                  )
                })}
              </div>
            </Panel>
          </div>
        </>
      )}
    </div>
  )
}

import { FileDown } from 'lucide-react'
import type { SchoolDirectoryState } from '../../app/useSchoolDirectory'
import { BackNav } from '../../components/BackNav'
import { LevelBadge } from '../../components/LevelBadge'
import { StatusPill } from '../../components/StatusPill'
import type { ReportSummaryFilters } from '../../api/reports'
import type { Assessment } from '../../fixtures/assessments'
import { PASS_MARK_PCT } from '../../lib/analytics'
import { scopeLabel } from '../../lib/reportScopes'
import { count } from '../../lib/time'
import { Panel } from '../classes/Panel'
import { BarList } from '../reports/BarList'
import { ReportKpiTile } from '../reports/ReportKpiTile'
import { useReportSummary } from '../reports/useReportSummary'
import { STATUS_META } from './statusMeta'

type AssessmentReportProps = {
  assessment: Assessment
  directory: SchoolDirectoryState
  token: string
  onBack: () => void
  onOpenStudent: (classId: string, studentId: string) => void
}

const fmtPct = (v: number | null) => (v === null ? '–' : `${Math.round(v)}%`)

/* The report for one assessment uses server analytics and the server's complete
   student outcome list. The PDF remains a separate report-generation feature. */
export function AssessmentReport({ assessment: a, directory, token, onBack, onOpenStudent }: AssessmentReportProps) {
  const school = directory.status === 'ready' ? directory.data : undefined
  const subjectId = Object.entries(school?.subjectNameById ?? {}).find(([, name]) => name === a.subject)?.[0]
  const term = Number(a.term.replace('Term ', ''))
  const request: ReportSummaryFilters | null = school && subjectId && Number.isInteger(term)
    ? { stream: a.stream, subjectId, year: a.year, term, assessmentName: a.name, assessmentId: a.id }
    : null
  const { response, error, isLoading, isOnline, retry } = useReportSummary(request, token)
  const s = response?.summary
  const outcomes = response?.student_outcomes ?? []
  const scored = s ? Object.values(s.performance_levels).reduce((total, value) => total + value, 0) : 0
  const hasRecordedOutcomes = outcomes.some((outcome) => outcome.status !== 'missing')
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

      {response && !isOnline && <p className="mb-4 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-foreground" role="status">Offline. Showing the report fetched earlier.</p>}
      {response && error && <p className="mb-4 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-foreground" role="status">Could not refresh this report. The last fetched figures are still shown.</p>}
      {!response && directory.status === 'error' && <p className="py-16 text-center text-sm text-muted-foreground">School data is unavailable on this device.</p>}
      {!response && isLoading && <p className="py-16 text-center text-sm text-muted-foreground" role="status">Loading assessment report…</p>}
      {!response && !isOnline && <p className="py-16 text-center text-sm text-muted-foreground">Reports need a connection. Marking continues to work offline.</p>}
      {!response && error && <div className="py-10 text-center"><p className="mb-3 text-sm text-muted-foreground">{error}</p><button type="button" onClick={retry} disabled={!isOnline} className="rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground disabled:opacity-40">Retry</button></div>}
      {response && s && !hasRecordedOutcomes ? (
        <p className="py-16 text-center text-sm text-muted-foreground">No marks for this assessment yet.</p>
      ) : response && s ? (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <ReportKpiTile label="Pass rate" primaryLabel={label} primary={{ value: fmtPct(s.pass_rate), n: scored, sub: `pass mark ${PASS_MARK_PCT}%` }} />
            <ReportKpiTile label="Mean score" primaryLabel={label} primary={{ value: fmtPct(s.mean_score), n: scored }} />
            <ReportKpiTile label="Score spread" primaryLabel={label} primary={{ value: s.score_spread ? `${Math.round(s.score_spread.min)}–${Math.round(s.score_spread.max)}` : '–', sub: s.score_spread ? `IQR ${s.score_spread.iqr.toFixed(1)} pts` : undefined, n: scored }} />
            <ReportKpiTile label="Entry completeness" warn={s.entry_completeness !== null && s.entry_completeness < 100} primaryLabel={label} primary={{ value: fmtPct(s.entry_completeness) }} />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {/* Criterion detail exists only where a marking grid does; for a
                record-only assessment the panel is dropped and student results
                take the full width rather than sit beside an empty box. */}
            {s.criterion_breakdown.length > 0 && (
              <Panel title="Criterion breakdown" aside="average share achieved">
                <div className="px-5 py-4">
                  <BarList rows={[...s.criterion_breakdown].sort((x, y) => y.pct - x.pct).map((c) => ({ label: c.name, pct: c.pct, detail: `n=${c.n}` }))} reference={PASS_MARK_PCT} />
                </div>
              </Panel>
            )}

            <Panel title="Student results" aside={count(outcomes.length, 'student')} className={s.criterion_breakdown.length === 0 ? 'lg:col-span-2' : undefined}>
              <div className="max-h-[420px] divide-y divide-border/40 overflow-y-auto">
                {outcomes.map((outcome) => (
                    <button key={outcome.studentId} type="button" onClick={() => onOpenStudent(outcome.classId, outcome.studentId)} className="flex w-full items-center gap-4 px-5 py-2.5 text-left transition-colors hover:bg-muted/30">
                      <span className="flex-1 truncate text-sm font-medium text-foreground">{outcome.studentName}</span>
                      {outcome.status === 'scored' && outcome.total !== null && outcome.max !== null && outcome.pct !== null && outcome.level ? (
                        <>
                          <span className="text-sm font-bold text-foreground tabular">{outcome.total}/{outcome.max}</span>
                          <span className="w-10 text-right text-xs text-muted-foreground tabular">{Math.round(outcome.pct)}%</span>
                          <LevelBadge level={outcome.level} />
                        </>
                      ) : (
                        <span className="text-xs text-muted-foreground">{outcome.status === 'absent' ? 'Absent' : 'Not entered'}</span>
                      )}
                    </button>
                ))}
              </div>
            </Panel>
          </div>
        </>
      ) : null}
    </div>
  )
}

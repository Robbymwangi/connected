import { GitCompareArrows, RefreshCw, Sparkles } from 'lucide-react'
import { useState } from 'react'
import { useCurrentUser } from '../../app/AuthContext'
import type { SchoolDirectoryState } from '../../app/useSchoolDirectory'
import type { ReportSummaryFilters } from '../../api/reports'
import { BarChart, LineChart, type Series } from '../../components/charts'
import { FilterDropdown } from '../../components/FilterDropdown'
import { LevelBadge } from '../../components/LevelBadge'
import { TERMS } from '../../fixtures/assessments'
import { HISTOGRAM_BINS, PASS_MARK_PCT, YEAR_TO_DATE, type ReportFilters, type Scope } from '../../lib/analytics'
import { PERFORMANCE_LEVELS, type PerformanceLevel } from '../../lib/grading'
import { gradeOf, scopeLabel } from '../../lib/reportScopes'
import { count } from '../../lib/time'
import { Panel } from '../classes/Panel'
import { BarList, type BarListRow } from './BarList'
import { ReportKpiTile } from './ReportKpiTile'
import { ScopePicker } from './ScopePicker'
import { AiDialog } from './AiDialog'
import { toReportView, type ReportView } from './reportModel'
import { useReportSummary } from './useReportSummary'

const TERM_OPTIONS = [YEAR_TO_DATE, ...TERMS] as const
const LEVEL_COLORS: Record<PerformanceLevel, 'success' | 'info' | 'warning' | 'danger'> = { EE: 'success', ME: 'info', AE: 'warning', BE: 'danger' }

type ReportsScreenProps = {
  directory: SchoolDirectoryState
  token: string
  onOpenStudent: (classId: string, studentId: string) => void
}

const fmtPct = (v: number | null) => (v === null ? '–' : `${Math.round(v)}%`)
const fmt1 = (v: number | null) => (v === null ? '–' : v.toFixed(1))

function requestFilters(scope: Scope | null, filters: ReportFilters, year: number | null, subjectNameById: Record<string, string> | undefined): ReportSummaryFilters | null {
  if (!scope || year === null || !subjectNameById) return null
  const subjectId = scope.subject === 'Overall'
    ? null
    : Object.entries(subjectNameById).find(([, name]) => name === scope.subject)?.[0]
  if (scope.subject !== 'Overall' && !subjectId) return null

  return {
    stream: scope.stream,
    year,
    subjectId,
    term: filters.term === YEAR_TO_DATE ? null : Number(filters.term.replace('Term ', '')),
    assessmentName: filters.assessment || null,
  }
}

function reportViewFor(
  response: NonNullable<ReturnType<typeof useReportSummary>['response']>,
  scope: Scope,
  year: number,
  directory: NonNullable<Extract<SchoolDirectoryState, { status: 'ready' }>['data']>,
) {
  const classIds = new Set(directory.classesForYear(year).filter((cls) => cls.stream === scope.stream).map((cls) => cls.id))
  const roster = directory.studentsForYear(year).filter((student) => classIds.has(student.classId))
  return toReportView(response, scope, roster)
}

export function ReportsScreen({ directory, token, onOpenStudent }: ReportsScreenProps) {
  const currentUser = useCurrentUser()
  const school = directory.status === 'ready' ? directory.data : undefined
  const [scope, setScope] = useState<Scope | null>(null)
  const [year, setYear] = useState<number | null>(null)
  const [filters, setFilters] = useState<ReportFilters>({ term: YEAR_TO_DATE, assessment: '' })
  const [comparing, setComparing] = useState(false)
  const [cmpScope, setCmpScope] = useState<Scope | null>(null)
  const [cmpFilters, setCmpFilters] = useState<ReportFilters>({ term: YEAR_TO_DATE, assessment: '' })
  const [metric, setMetric] = useState<'passRate' | 'meanPct'>('passRate')
  const [aiOpen, setAiOpen] = useState(false)
  /* Remount the dialog per opening so its conversation and ring start fresh. */
  const [aiOpenings, setAiOpenings] = useState(0)

  const activeYear = year ?? school?.years[0] ?? null
  const scopeClasses = school?.classesForYear(activeYear ?? 0) ?? []
  const teacher = school?.teachers.find((item) => item.id === currentUser.id)
  const defaultStream = teacher?.homeStream ?? scopeClasses[0]?.stream
  const defaultClass = scopeClasses.find((item) => item.stream === defaultStream)
  const defaultScope: Scope | null = defaultStream
    ? { stream: defaultStream, subject: teacher?.subjectsByStream[defaultStream]?.[0] ?? defaultClass?.subjects[0] ?? 'Overall' }
    : null
  const activeScope = scope ?? defaultScope
  const grade = activeScope ? gradeOf(activeScope.stream, scopeClasses) : undefined
  const primaryRequest = requestFilters(activeScope, filters, activeYear, school?.subjectNameById)
  const compareRequest = comparing ? requestFilters(cmpScope, cmpFilters, activeYear, school?.subjectNameById) : null
  const primary = useReportSummary(primaryRequest, token)
  const compare = useReportSummary(compareRequest, token)
  const report = primary.response && activeScope && activeYear !== null && school ? reportViewFor(primary.response, activeScope, activeYear, school) : null
  const cmp = compare.response && cmpScope && activeYear !== null && school ? reportViewFor(compare.response, cmpScope, activeYear, school) : null

  const localYears = school?.years ?? []
  const yearOptions = [...new Set([...(primary.response?.available_years ?? []), ...localYears, ...(activeYear === null ? [] : [activeYear])])]
    .sort((a, b) => b - a)
    .map(String)
  const assessmentOptions = (available: string[] | undefined) => ['All assessments', ...(available ?? [])]
  const controlsDisabled = !primary.isOnline || !school

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">Reports</h1>
          <p className="mt-1 text-sm font-medium text-muted-foreground">
            {report ? `${count(report.assessmentCount, 'assessment')} · ${count(report.roster.length, 'student')}` : 'Choose a scope'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <ScopePicker
            label="Scope"
            value={activeScope}
            onChange={(s) => {
              setScope(s)
              setFilters((f) => ({ ...f, assessment: '' }))
              /* The compare scope is only meaningful within the same grade. */
              if (cmpScope && gradeOf(cmpScope.stream, scopeClasses) !== gradeOf(s.stream, scopeClasses)) setCmpScope(null)
            }}
            teacher={teacher}
            classes={scopeClasses}
            disabled={controlsDisabled}
          />
          <FilterDropdown
            label="Year"
            value={activeYear?.toString() ?? 'Year'}
            options={yearOptions.length ? yearOptions : ['Year']}
            disabled={controlsDisabled || yearOptions.length === 0}
            onChange={(value) => { setYear(Number(value)); setScope(null); setCmpScope(null); setFilters((f) => ({ ...f, assessment: '' })) }}
          />
          <FilterDropdown label="Term" value={filters.term as (typeof TERM_OPTIONS)[number]} options={TERM_OPTIONS} onChange={(term) => setFilters({ term, assessment: '' })} disabled={controlsDisabled} />
          <FilterDropdown label="Assessment" value={filters.assessment || 'All assessments'} options={assessmentOptions(primary.response?.available_assessments)} onChange={(name) => setFilters((f) => ({ ...f, assessment: name === 'All assessments' ? '' : name }))} disabled={controlsDisabled} />
          <button
            type="button"
            onClick={primary.retry}
            aria-label="Refresh report"
            title="Refresh report"
            disabled={controlsDisabled || primary.isLoading || !report}
            className="rounded-xl border border-border bg-card p-2 text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
          >
            <RefreshCw className="size-4" />
          </button>
          <button
            type="button"
            onClick={() => setComparing((v) => !v)}
            aria-pressed={comparing}
            className={`flex items-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-medium transition-colors ${
              comparing ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card text-foreground hover:bg-muted'
            }`}
          >
            <GitCompareArrows className="size-4" /> Compare
          </button>
          <span className={`orbit rounded-xl ${report ? 'orbit--slow' : ''}`}>
            <button
              type="button"
              onClick={() => {
                setAiOpenings((n) => n + 1)
                setAiOpen(true)
              }}
              disabled={!report}
              className="flex items-center gap-1.5 rounded-xl border border-primary/30 bg-primary/10 px-3 py-2 text-sm font-medium text-primary transition-colors hover:bg-primary/15 disabled:opacity-40"
            >
              <Sparkles className="size-4" /> Ask AI
            </button>
          </span>
        </div>
      </div>

      {comparing && (
        <div className="mb-5 flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-border bg-muted/30 px-3 py-2.5">
          <span className="text-xs font-semibold text-muted-foreground">Against</span>
          <ScopePicker label="Compare scope" value={cmpScope} onChange={(s) => { setCmpScope(s); setCmpFilters((f) => ({ ...f, assessment: '' })) }} teacher={teacher} classes={scopeClasses} disabled={controlsDisabled} lockedGrade={grade} />
          <FilterDropdown label="Compare term" value={cmpFilters.term as (typeof TERM_OPTIONS)[number]} options={TERM_OPTIONS} onChange={(term) => setCmpFilters({ term, assessment: '' })} disabled={controlsDisabled} />
          <FilterDropdown label="Compare assessment" value={cmpFilters.assessment || 'All assessments'} options={assessmentOptions(compare.response?.available_assessments)} onChange={(name) => setCmpFilters((f) => ({ ...f, assessment: name === 'All assessments' ? '' : name }))} disabled={controlsDisabled || !cmpScope} />
          {grade && <span className="text-[11px] text-muted-foreground">Same grade only ({grade}), so the rubrics match.</span>}
        </div>
      )}

      {report && !primary.isOnline && <p className="mb-4 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-foreground" role="status">Offline. Showing the report fetched earlier. Filters and refresh need a connection.</p>}
      {report && primary.error && <p className="mb-4 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-foreground" role="status">Could not refresh this report. The last fetched figures are still shown.</p>}
      {!report && directory.status === 'error' && <p className="py-16 text-center text-sm text-muted-foreground">School data is unavailable on this device.</p>}
      {!report && directory.status === 'ready' && primary.isLoading && <p className="py-16 text-center text-sm text-muted-foreground" role="status">Loading report…</p>}
      {!report && directory.status === 'ready' && !primary.isOnline && <p className="py-16 text-center text-sm text-muted-foreground">Reports need a connection. Marking continues to work offline.</p>}
      {!report && primary.error && <div className="py-10 text-center"><p className="mb-3 text-sm text-muted-foreground">{primary.error}</p><button type="button" onClick={primary.retry} disabled={!primary.isOnline} className="rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground disabled:opacity-40">Retry</button></div>}
      {report && <ReportBody report={report} cmp={cmp} metric={metric} onMetric={setMetric} onOpenStudent={onOpenStudent} />}
      {report && (
        <AiDialog
          key={aiOpenings}
          open={aiOpen}
          onClose={() => setAiOpen(false)}
          report={{ scopeLabel: scopeLabel(report.scope), summary: report.summary, criteria: report.criteria, trend: report.trend, attention: report.attention, roster: report.roster }}
        />
      )}
    </div>
  )
}

function ReportBody({ report, cmp, metric, onMetric, onOpenStudent }: { report: ReportView; cmp: ReportView | null; metric: 'passRate' | 'meanPct'; onMetric: (m: 'passRate' | 'meanPct') => void; onOpenStudent: (classId: string, studentId: string) => void }) {
  const s = report.summary
  const c = cmp?.summary
  const pLabel = scopeLabel(report.scope)
  const cLabel = cmp ? scopeLabel(cmp.scope) : undefined
  const lowCompleteness = s.completeness !== null && s.completeness < 100

  if (report.assessmentCount === 0) {
    return <p className="py-16 text-center text-sm text-muted-foreground">No assessments with marks in this scope yet.</p>
  }

  const levelRows: BarListRow[] = (Object.keys(PERFORMANCE_LEVELS) as PerformanceLevel[]).map((lv) => ({
    label: `${lv} · ${PERFORMANCE_LEVELS[lv].label}`,
    pct: s.scored ? (s.levels[lv] / s.scored) * 100 : 0,
    detail: `(${s.levels[lv]})`,
    color: LEVEL_COLORS[lv],
    compare: c ? { pct: c.scored ? (c.levels[lv] / c.scored) * 100 : 0, detail: `(${c.levels[lv]})` } : undefined,
  }))
  const criteriaRows: BarListRow[] = report.criteria.map((r) => ({
    label: r.name,
    pct: r.pct,
    detail: `n=${r.n}`,
    compare: cmp ? (() => { const m = cmp.criteria.find((x) => x.name === r.name); return m ? { pct: m.pct, detail: `n=${m.n}` } : undefined })() : undefined,
  }))
  const histSeries: Series[] = [
    { id: 'p', label: pLabel, color: 'primary', values: s.histogram.map((b) => b.count) },
    ...(c ? [{ id: 'c', label: cLabel ?? '', color: 'muted' as const, values: c.histogram.map((b) => b.count) }] : []),
  ]
  /* Two scopes may have sat different assessments; align the lines by the point's
     key (subject, name, term), with a gap where one scope has no result, rather
     than by position. Labels carry the subject when a scope spans subjects. */
  const allPoints = [...report.trend, ...(cmp?.trend ?? [])]
  /* One entry per distinct assessment (by key), ordered by the earliest date that
     key appears on, so a compare-only assessment slots into the timeline rather
     than being appended at the end. */
  const byKey = new Map<string, { date: string; point: (typeof allPoints)[number] }>()
  for (const t of allPoints) {
    const seen = byKey.get(t.key)
    if (!seen || t.date < seen.date) byKey.set(t.key, { date: t.date, point: t })
  }
  const keys = [...byKey.entries()].sort((a, b) => a[1].date.localeCompare(b[1].date)).map(([k]) => k)
  const multiSubject = new Set(allPoints.map((t) => t.subject)).size > 1
  const trendCategories = keys.map((k) => {
    const t = byKey.get(k)!.point
    return multiSubject ? `${t.subject} ${t.label}` : t.label
  })
  const along = (points: ReportView['trend']) => keys.map((k) => points.find((t) => t.key === k)?.[metric] ?? null)
  const trendSeries: Series[] = [
    { id: 'p', label: pLabel, color: 'primary', values: along(report.trend) },
    ...(cmp ? [{ id: 'c', label: cLabel ?? '', color: 'muted' as const, values: along(cmp.trend) }] : []),
  ]

  return (
    <>
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <ReportKpiTile label="Pass rate" primaryLabel={pLabel} compareLabel={cLabel} primary={{ value: fmtPct(s.passRate), n: s.scored, sub: `pass mark ${PASS_MARK_PCT}%` }} compare={c && { value: fmtPct(c.passRate), n: c.scored }} />
        <ReportKpiTile label="Mean score" primaryLabel={pLabel} compareLabel={cLabel} primary={{ value: fmtPct(s.meanPct), n: s.scored }} compare={c && { value: fmtPct(c.meanPct), n: c.scored }} />
        <ReportKpiTile label="Score spread" primaryLabel={pLabel} compareLabel={cLabel} primary={{ value: s.spread ? `${Math.round(s.spread.min)}–${Math.round(s.spread.max)}` : '–', sub: s.spread ? `IQR ${fmt1(s.spread.iqr)} pts` : undefined, n: s.scored }} compare={c && { value: c.spread ? `${Math.round(c.spread.min)}–${Math.round(c.spread.max)}` : '–', sub: c.spread ? `IQR ${fmt1(c.spread.iqr)} pts` : undefined, n: c.scored }} />
        <ReportKpiTile label="Entry completeness" warn={lowCompleteness} primaryLabel={pLabel} compareLabel={cLabel} primary={{ value: fmtPct(s.completeness) }} compare={c && { value: fmtPct(c.completeness) }} />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title="Performance distribution" aside={count(s.scored, 'result')}>
          <div className="px-5 py-4"><BarList rows={levelRows} /></div>
        </Panel>

        <Panel title="Score distribution" aside="percent of maximum, 10-point bins">
          <div className="px-3 py-3">
            <BarChart label={`Number of results in each ten-point band for ${pLabel}`} categories={HISTOGRAM_BINS} series={histSeries} height={170} />
            <p className="mt-1 px-1 text-[10px] text-muted-foreground">Pass mark at {PASS_MARK_PCT}%: bands from {PASS_MARK_PCT}–{PASS_MARK_PCT + 9} upward.</p>
          </div>
        </Panel>

        <Panel
          title="Trend across assessments"
          aside={
            <span className="flex items-center gap-1 rounded-lg bg-muted/60 p-0.5" role="group" aria-label="Trend metric">
              {(['passRate', 'meanPct'] as const).map((m) => (
                <button key={m} type="button" aria-pressed={metric === m} onClick={() => onMetric(m)} className={`rounded-md px-2 py-0.5 text-[11px] font-semibold transition-colors ${metric === m ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}>
                  {m === 'passRate' ? 'Pass rate' : 'Mean'}
                </button>
              ))}
            </span>
          }
        >
          <div className="px-3 py-3">
            {report.trend.length < 2 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">A trend needs at least two assessments in scope.</p>
            ) : (
              <LineChart label={`${metric === 'passRate' ? 'Pass rate' : 'Mean score'} per assessment for ${pLabel}`} categories={trendCategories} series={trendSeries} max={100} format={(v) => `${Math.round(v)}%`} referenceLine={{ value: PASS_MARK_PCT, label: 'Pass mark' }} height={180} />
            )}
          </div>
        </Panel>

        {report.scope.subject !== 'Overall' && <Panel title="Rubric criterion breakdown" aside="average share achieved, from marked grids">
          <div className="px-5 py-4">
            {criteriaRows.length === 0 ? (
              <p className="py-6 text-center text-sm text-muted-foreground">No marked grids in scope; criterion detail comes from the grid.</p>
            ) : (
              <BarList rows={criteriaRows} reference={PASS_MARK_PCT} />
            )}
          </div>
        </Panel>}

        <Panel title="Students needing attention" aside={count(report.attention.length, 'student')} className="lg:col-span-2">
          {report.attention.length === 0 ? (
            <p className="px-5 py-6 text-center text-sm text-muted-foreground">Nobody is averaging below the pass mark in this scope.</p>
          ) : (
            <div className="divide-y divide-border/40">
              {report.attention.map((a) => {
                const student = report.roster.find((st) => st.id === a.studentId)
                if (!student) return null
                return (
                  <button key={a.studentId} type="button" onClick={() => onOpenStudent(student.classId, student.id)} className="flex w-full items-center gap-4 px-5 py-3 text-left transition-colors hover:bg-muted/30">
                    <span className="flex-1 text-sm font-medium text-foreground">{student.name}</span>
                    <span className="text-xs text-muted-foreground">avg <span className="font-semibold text-foreground tabular">{Math.round(a.meanPct)}%</span> over {count(a.scored, 'result')}</span>
                    <span className="text-xs text-muted-foreground">latest <span className="font-semibold text-foreground tabular">{Math.round(a.latestPct)}%</span></span>
                    <LevelBadge level={a.level} />
                  </button>
                )
              })}
            </div>
          )}
        </Panel>
      </div>
    </>
  )
}

import { Undo2, UserX } from 'lucide-react'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { BackNav } from '../../../components/BackNav'
import { LevelBadge } from '../../../components/LevelBadge'
import { StatusPill } from '../../../components/StatusPill'
import { Toast, type ToastKind } from '../../../components/Toast'
import type { Assessment } from '../../../fixtures/assessments'
import { classes } from '../../../fixtures/classes'
import type { ActiveConflict, Choice } from '../../../fixtures/conflicts'
import type { Grid } from '../../../fixtures/marks'
import { rubricFor } from '../../../fixtures/rubrics'
import { initials, rosterFor } from '../../../fixtures/students'
import { useConnectivity } from '../../../lib/connectivity'
import { ABSENT, EMPTY, performanceLevel, rowTotal, type Mark } from '../../../lib/grading'
import { STATUS_META } from '../statusMeta'
import { ConflictDialog } from './ConflictDialog'
import { FinalizeDialog } from './FinalizeDialog'
import { MarkCell, type GridMove } from './MarkCell'

type Focus = { studentId: string; criterionId: string }

type MarkingGridProps = {
  assessment: Assessment
  /* The marks live in the session store, not here, so edits survive navigation. */
  grid: Grid
  onUpdateGrid: (update: (grid: Grid) => Grid) => void
  /* Conflicts for this assessment only, and the three ways to move one (ADR 0002). */
  conflicts: ActiveConflict[]
  onResolveConflict: (id: string, choice: Choice, note: string) => void
  onProposeResolution: (id: string, choice: Choice, note: string) => void
  onAcceptProposal: (id: string) => void
  onReferConflict: (id: string) => void
  onFinalize: (assessmentId: string) => void
  onBack: () => void
}

export function MarkingGrid({
  assessment,
  grid,
  onUpdateGrid: setGrid,
  conflicts,
  onResolveConflict,
  onProposeResolution,
  onAcceptProposal,
  onReferConflict,
  onFinalize,
  onBack,
}: MarkingGridProps) {
  const rubric = rubricFor(assessment.subject)
  const maxTotal = rubric.reduce((sum, c) => sum + c.max, 0)
  const cls = classes.find((c) => c.stream === assessment.stream)
  const roster = rosterFor(cls?.id ?? '')
  const finalized = assessment.status === 'finalized' || assessment.status === 'reports-generated'

  const [editing, setEditing] = useState(false)
  const [focus, setFocus] = useState<Focus | null>(null)
  const [openConflictId, setOpenConflictId] = useState<string | null>(null)
  const [finalizeOpen, setFinalizeOpen] = useState(false)
  const [toast, setToast] = useState<ToastKind | null>(null)
  const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const showToast = (kind: ToastKind, ms: number | null) => {
    if (toastTimer.current) clearTimeout(toastTimer.current)
    setToast(kind)
    if (ms !== null) toastTimer.current = setTimeout(() => setToast(null), ms)
  }

  /* Connectivity changes while marking. Offline stays up until the link returns;
     the others clear themselves. */
  useConnectivity({
    onChange: (online) => (online ? showToast('syncing', 4000) : showToast('offline', null)),
  })
  useEffect(() => () => {
    if (toastTimer.current) clearTimeout(toastTimer.current)
  }, [])

  const canEdit = editing && !finalized

  const setMark = (studentId: string, criterionId: string, mark: Mark, extra?: Partial<Grid[string][string]>) =>
    setGrid((g) => ({
      ...g,
      [studentId]: {
        ...g[studentId],
        [criterionId]: { ...g[studentId][criterionId], mark, sync: 'local', ...extra },
      },
    }))

  const setRow = (studentId: string, mark: Mark) =>
    setGrid((g) => ({
      ...g,
      [studentId]: Object.fromEntries(
        rubric.map((c) => [c.id, { ...g[studentId][c.id], mark, sync: 'local' as const }]),
      ),
    }))

  const move = (from: Focus, how: GridMove) => {
    const rows = roster.map((s) => s.id)
    const cols = rubric.map((c) => c.id)
    let r = rows.indexOf(from.studentId)
    let c = cols.indexOf(from.criterionId)
    if (how === 'next-row') r += 1
    if (how === 'prev-row') r -= 1
    if (how === 'next-cell') {
      c += 1
      if (c >= cols.length) { c = 0; r += 1 }
    }
    if (how === 'prev-cell') {
      c -= 1
      if (c < 0) { c = cols.length - 1; r -= 1 }
    }
    if (r < 0 || r >= rows.length) return
    setFocus({ studentId: rows[r], criterionId: cols[c] })
  }

  const conflictAt = (studentId: string, criterionId: string) =>
    conflicts.find((k) => k.studentId === studentId && k.criterionId === criterionId)
  const openConflict = conflicts.find((k) => k.id === openConflictId) ?? null

  const finalize = () => {
    onFinalize(assessment.id)
    setEditing(false)
    setFinalizeOpen(false)
  }

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <BackNav
        onBack={onBack}
        items={[
          { label: 'Assessments', onClick: onBack },
          { label: `${assessment.subject} · ${assessment.stream}` },
          { label: assessment.name },
        ]}
      />

      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">
            {assessment.subject}: {assessment.stream}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {assessment.name} · {assessment.term}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <StatusPill tone={STATUS_META[assessment.status].tone} size="sm">
            {STATUS_META[assessment.status].label}
          </StatusPill>
          {!finalized && (
            <button
              type="button"
              onClick={() => setEditing((v) => !v)}
              aria-pressed={editing}
              className={`rounded-xl border px-3 py-1.5 text-xs font-semibold transition-colors ${
                editing
                  ? 'border-primary bg-primary text-primary-foreground'
                  : 'border-border bg-card text-foreground hover:bg-muted'
              }`}
            >
              {editing ? 'Done' : 'Edit'}
            </button>
          )}
          {!finalized && assessment.status !== 'scheduled' && (
            <button
              type="button"
              onClick={() => setFinalizeOpen(true)}
              className="rounded-xl border border-warning/30 bg-warning/10 px-3 py-1.5 text-xs font-semibold text-warning transition-colors hover:bg-warning/20"
            >
              Finalize
            </button>
          )}
        </div>
      </div>

      {canEdit && (
        <div className="mb-3 flex flex-wrap items-center gap-4 rounded-xl border border-border bg-muted/40 px-3 py-2 text-[10px] text-muted-foreground">
          <span><Kbd>Enter</Kbd> / <Kbd>↓</Kbd> next student</span>
          <span><Kbd>Tab</Kbd> next criterion</span>
          <span><Kbd>Shift+Tab</Kbd> previous</span>
          <span><Kbd>Esc</Kbd> revert cell</span>
          <span><Kbd>A</Kbd> absent</span>
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-border bg-card shadow-sm">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="border-b border-border/60 bg-muted/30">
              <th scope="col" className="sticky left-0 z-10 min-w-40 bg-muted/40 px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase">
                Student
              </th>
              {rubric.map((c) => (
                <th key={c.id} scope="col" className="min-w-[90px] px-3 py-3 text-center text-xs font-bold tracking-wide text-muted-foreground uppercase">
                  {c.name}
                  <span className="block text-[9px] font-normal text-muted-foreground/60 normal-case">/{c.max}</span>
                </th>
              ))}
              <th scope="col" className="min-w-[70px] px-3 py-3 text-center text-xs font-bold tracking-wide text-muted-foreground uppercase">
                Total
                <span className="block text-[9px] font-normal text-muted-foreground/60 normal-case">/{maxTotal}</span>
              </th>
              <th scope="col" className="min-w-14 px-3 py-3 text-center text-xs font-bold tracking-wide text-muted-foreground uppercase">
                Level
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border/40">
            {roster.map((student) => {
              const row = grid[student.id]
              const total = rowTotal(rubric.map((c) => row[c.id].mark))
              const allAbsent = rubric.every((c) => row[c.id].mark.kind === 'absent')
              return (
                <tr key={student.id} className="group transition-colors hover:bg-muted/20">
                  <th scope="row" className="sticky left-0 z-10 bg-card px-4 py-2.5 text-left font-normal transition-colors group-hover:bg-muted/20">
                    <div className="flex items-center gap-2.5">
                      <div className="flex size-7 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-[10px] font-bold text-primary">
                        {initials(student.name)}
                      </div>
                      <span className="truncate text-sm font-medium text-foreground">{student.name}</span>
                      {canEdit && (
                        <button
                          type="button"
                          onClick={() => setRow(student.id, allAbsent ? EMPTY : ABSENT)}
                          aria-label={allAbsent ? `Clear absence for ${student.name}` : `Mark ${student.name} absent`}
                          title={allAbsent ? 'Clear absence' : 'Mark absent'}
                          className="ml-auto rounded-md p-1 text-muted-foreground opacity-0 transition-all group-hover:opacity-100 focus-visible:opacity-100 hover:bg-muted hover:text-foreground"
                        >
                          {allAbsent ? <Undo2 className="size-4" /> : <UserX className="size-4" />}
                        </button>
                      )}
                    </div>
                  </th>
                  {rubric.map((c) => {
                    const contested = conflictAt(student.id, c.id)
                    return (
                      <td key={c.id} className={`px-2 py-1.5 text-center ${contested ? 'bg-danger/10' : ''}`}>
                        <MarkCell
                          cell={row[c.id]}
                          max={c.max}
                          editing={canEdit}
                          focused={focus?.studentId === student.id && focus?.criterionId === c.id}
                          contested={contested !== undefined}
                          onFocus={() => setFocus({ studentId: student.id, criterionId: c.id })}
                          onCommit={(mark) => setMark(student.id, c.id, mark)}
                          onMove={(how) => move({ studentId: student.id, criterionId: c.id }, how)}
                          onOpenConflict={() => contested && setOpenConflictId(contested.id)}
                        />
                      </td>
                    )
                  })}
                  <td className="px-3 py-2.5 text-center">
                    <span className="text-sm font-black text-foreground tabular">
                      {total ?? <span className="font-normal text-muted-foreground/40">–</span>}
                    </span>
                  </td>
                  <td className="px-3 py-2.5 text-center">
                    {total !== null && <LevelBadge level={performanceLevel(total, maxTotal)} />}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <ConflictDialog
        conflict={openConflict}
        subject={assessment.subject}
        onResolve={(choice, note) => {
          if (!openConflict) return
          onResolveConflict(openConflict.id, choice, note)
          showToast('resolved', 5000)
        }}
        onPropose={(choice, note) => openConflict && onProposeResolution(openConflict.id, choice, note)}
        onAccept={() => {
          if (!openConflict) return
          onAcceptProposal(openConflict.id)
          showToast('resolved', 5000)
        }}
        onRefer={() => openConflict && onReferConflict(openConflict.id)}
        onClose={() => setOpenConflictId(null)}
      />
      <FinalizeDialog open={finalizeOpen} onClose={() => setFinalizeOpen(false)} onConfirm={finalize} />
      {toast && <Toast kind={toast} />}
    </div>
  )
}

function Kbd({ children }: { children: ReactNode }) {
  return (
    <kbd className="rounded border border-border bg-card px-1 py-0.5 font-mono">{children}</kbd>
  )
}

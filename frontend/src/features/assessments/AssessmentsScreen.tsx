import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useCurrentUser } from '../../app/AuthContext'
import type { SessionStore } from '../../app/useSessionStore'
import type { SchoolDirectoryState } from '../../app/useSchoolDirectory'
import { Modal } from '../../components/Modal'
import type { Assessment } from '../../fixtures/assessments'
import { defaultFilters, filterAssessments, type QueueFilters } from '../../lib/assessmentQueue'
import { MarkingGrid } from './grid/MarkingGrid'
import { AssessmentReport } from './AssessmentReport'
import { BrowseTree } from './BrowseTree'
import { CreateAssessmentDialog, type NewAssessment } from './CreateAssessmentDialog'
import { QueueList } from './QueueList'
import { useTermClosures } from './useTermClosures'

type View = 'queue' | 'browse'

const NO_TERM_OPEN = 'No term is open, so no assessment can be created. The school office opens each term.'

type AssessmentsScreenProps = {
  store: SessionStore
  directory: SchoolDirectoryState
  token: string
  /* Set when the user is inside one assessment; undefined on the list. */
  assessmentId?: string
  view?: 'grid' | 'report'
  /* Arrive with the create dialog already open, from the dashboard. */
  creating?: boolean
  onOpen: (assessmentId: string, view: 'grid' | 'report') => void
  onBackToList: () => void
  onOpenStudent: (classId: string, studentId: string) => void
  /* Called when the create dialog closes, so the URL can drop its ?new. */
  onCreateClosed?: () => void
}

export function AssessmentsScreen({
  store,
  directory,
  token,
  assessmentId,
  view,
  creating: creatingOnArrival = false,
  onOpen,
  onBackToList,
  onOpenStudent,
  onCreateClosed,
}: AssessmentsScreenProps) {
  const { assessments: list, conflicts } = store
  const closures = useTermClosures(useCurrentUser().id)
  const [listView, setListView] = useState<View>('queue')
  const [filters, setFilters] = useState<QueueFilters>(() => defaultFilters())
  const [creating, setCreating] = useState(creatingOnArrival)
  const [deleteCandidate, setDeleteCandidate] = useState<Assessment | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [deleting, setDeleting] = useState(false)
  const school = directory.status === 'ready' ? directory.data : null
  const referenceYear = school?.years[0] ?? new Date().getFullYear()
  const referenceClasses = school?.classesForYear(referenceYear) ?? []
  const referenceGrades = [...new Set(referenceClasses.map((cls) => cls.grade))]
  const openTerm = school?.openTerm ?? null
  const termClasses = openTerm ? school?.classesForYear(openTerm.year) ?? [] : []

  const open = assessmentId ? list.find((a) => a.id === assessmentId) : undefined
  if (open && view === 'grid') {
    return (
      <MarkingGrid
        assessment={open}
        directory={directory}
        grid={store.gridFor(open.id)}
        onUpdateGrid={(update) => store.updateGrid(open.id, update)}
        conflicts={conflicts.filter((k) => k.assessmentId === open.id)}
        onResolveConflict={store.resolveConflict}
        onProposeResolution={store.proposeResolution}
        onAcceptProposal={store.acceptProposal}
        onReferConflict={store.referConflict}
        onFinalize={store.finalizeAssessment}
        token={token}
        onBack={onBackToList}
      />
    )
  }
  if (open && view === 'report') {
    return <AssessmentReport assessment={open} directory={directory} token={token} onBack={onBackToList} onOpenStudent={onOpenStudent} />
  }

  const shown = filterAssessments(list, filters).length
  const inProgress = list.filter((a) => a.status === 'in-progress').length

  /* One scheduled assessment per chosen stream, in the open term: its year and term
     both come from the calendar (ADR 0014), and the streams are that year's. The store
     assigns each record its UUID. */
  const create = async (draft: NewAssessment) => {
    const year = draft.term.year
    const created: Omit<Assessment, 'id' | 'version'>[] = draft.classIds.flatMap((classId) => {
      const cls = school?.classesForYear(year).find((c) => c.id === classId)
      if (!cls) return []
      return [{
        subject: draft.subject,
        stream: cls.stream,
        name: draft.name,
        term: `Term ${draft.term.term}`,
        year,
        date: draft.date,
        entered: 0,
        total: cls.enrolment,
        status: 'scheduled',
        sync: 'pending',
      }]
    })
    await store.addAssessments(created)
    /* Show the year the new records landed in, so they are not filtered out of view. */
    setFilters((f) => ({ ...f, year }))
  }

  const confirmDelete = async () => {
    if (!deleteCandidate) return
    setDeleting(true)
    setDeleteError(null)
    try {
      await store.deleteAssessment(deleteCandidate.id)
      setDeleteCandidate(null)
    } catch (error) {
      setDeleteError(error instanceof Error ? error.message : 'Could not delete this assessment on this device.')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">Assessments</h1>
          <p className="mt-1 text-sm font-medium text-muted-foreground">
            {listView === 'queue' ? `${shown} shown` : `${list.length} total`} · {inProgress} in progress
          </p>
          {school && !openTerm && <p className="mt-1 text-sm text-muted-foreground" data-testid="no-term-open">{NO_TERM_OPEN}</p>}
        </div>
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1 rounded-xl border border-border bg-muted/60 p-1" role="group" aria-label="View">
            {(['queue', 'browse'] as const).map((v) => (
              <button
                key={v}
                type="button"
                aria-pressed={listView === v}
                onClick={() => setListView(v)}
                className={`rounded-lg px-3 py-1.5 text-xs font-semibold capitalize transition-colors ${
                  listView === v ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
                }`}
              >
                {v}
              </button>
            ))}
          </div>
          <button
            type="button"
            onClick={() => setCreating(true)}
            disabled={!openTerm || !school || school.subjects.length === 0 || termClasses.length === 0}
            title={openTerm ? undefined : NO_TERM_OPEN}
            className="flex items-center gap-1.5 rounded-xl bg-primary px-3 py-2 text-xs font-bold text-primary-foreground transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <Plus className="size-4" /> New
          </button>
        </div>
      </div>

      {listView === 'queue' ? (
        <QueueList
          assessments={list}
          subjects={school?.subjects ?? []}
          filters={filters}
          onFiltersChange={setFilters}
          onOpenGrid={(id) => onOpen(id, 'grid')}
          onOpenReport={(id) => onOpen(id, 'report')}
          onDelete={(id) => { setDeleteError(null); setDeleteCandidate(list.find((item) => item.id === id) ?? null) }}
        />
      ) : (
        <BrowseTree
          assessments={list}
          classes={referenceClasses}
          grades={referenceGrades}
          onOpenGrid={(id) => onOpen(id, 'grid')}
          onOpenReport={(id) => onOpen(id, 'report')}
          onDelete={(id) => { setDeleteError(null); setDeleteCandidate(list.find((item) => item.id === id) ?? null) }}
        />
      )}

      <Modal
        open={deleteCandidate !== null}
        onClose={() => { if (!deleting) setDeleteCandidate(null) }}
        title="Delete scheduled assessment?"
        size="sm"
        footer={(
          <>
            <button type="button" onClick={() => setDeleteCandidate(null)} disabled={deleting} className="rounded-xl border border-border px-4 py-2.5 text-sm font-semibold text-muted-foreground transition-colors hover:bg-muted disabled:opacity-50">Cancel</button>
            <button type="button" onClick={() => void confirmDelete()} disabled={deleting} className="rounded-xl border border-danger/30 bg-danger/10 px-4 py-2.5 text-sm font-semibold text-danger transition-colors hover:bg-danger/15 disabled:opacity-50">{deleting ? 'Deleting…' : 'Delete assessment'}</button>
          </>
        )}
      >
        {deleteCandidate && <div className="space-y-3 text-sm text-foreground">
          <p><strong>{deleteCandidate.subject}: {deleteCandidate.name}</strong> · Stream {deleteCandidate.stream}</p>
          <p>This scheduled assessment has no marks. Deletion syncs to this school’s devices; its history is retained.</p>
          {deleteError && <p className="text-danger" role="alert">{deleteError}</p>}
        </div>}
      </Modal>

      {openTerm && <CreateAssessmentDialog
        open={creating}
        term={openTerm}
        classes={termClasses}
        subjects={school?.subjects ?? []}
        criteriaBySubject={school?.criteriaBySubject ?? {}}
        closures={closures}
        onClose={() => {
          setCreating(false)
          onCreateClosed?.()
        }}
        onCreate={create}
      />}
    </div>
  )
}

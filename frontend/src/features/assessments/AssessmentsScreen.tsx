import { Plus } from 'lucide-react'
import { useState } from 'react'
import type { SessionStore } from '../../app/useSessionStore'
import type { Assessment } from '../../fixtures/assessments'
import { classes } from '../../fixtures/classes'
import { DEFAULT_FILTERS, filterAssessments, type QueueFilters } from '../../lib/assessmentQueue'
import { MarkingGrid } from './grid/MarkingGrid'
import { AssessmentReport } from './AssessmentReport'
import { BrowseTree } from './BrowseTree'
import { CreateAssessmentDialog, type NewAssessment } from './CreateAssessmentDialog'
import { QueueList } from './QueueList'

type View = 'queue' | 'browse'

type AssessmentsScreenProps = {
  store: SessionStore
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
  assessmentId,
  view,
  creating: creatingOnArrival = false,
  onOpen,
  onBackToList,
  onOpenStudent,
  onCreateClosed,
}: AssessmentsScreenProps) {
  const { assessments: list, conflicts } = store
  const [listView, setListView] = useState<View>('queue')
  const [filters, setFilters] = useState<QueueFilters>(DEFAULT_FILTERS)
  const [creating, setCreating] = useState(creatingOnArrival)

  const open = assessmentId ? list.find((a) => a.id === assessmentId) : undefined
  if (open && view === 'grid') {
    return (
      <MarkingGrid
        assessment={open}
        grid={store.gridFor(open.id)}
        onUpdateGrid={(update) => store.updateGrid(open.id, update)}
        conflicts={conflicts.filter((k) => k.assessmentId === open.id)}
        onResolveConflict={store.resolveConflict}
        onProposeResolution={store.proposeResolution}
        onAcceptProposal={store.acceptProposal}
        onReferConflict={store.referConflict}
        onFinalize={store.finalizeAssessment}
        onBack={onBackToList}
      />
    )
  }
  if (open && view === 'report') {
    return <AssessmentReport assessment={open} store={store} onBack={onBackToList} onOpenStudent={onOpenStudent} />
  }

  const shown = filterAssessments(list, filters).length
  const inProgress = list.filter((a) => a.status === 'in-progress').length

  /* One scheduled assessment per chosen stream. Term comes from the dialog and the
     year from the chosen date's calendar year; neither is inferred from the other,
     because no school-calendar rule exists yet to map dates onto terms. The store
     assigns each record its UUID. */
  const create = (draft: NewAssessment) => {
    const created: Omit<Assessment, 'id' | 'version'>[] = draft.classIds.flatMap((classId) => {
      const cls = classes.find((c) => c.id === classId)
      if (!cls) return []
      return [{
        subject: draft.subject,
        stream: cls.stream,
        name: draft.name,
        term: draft.term,
        year: Number(draft.date.slice(0, 4)),
        date: draft.date,
        entered: 0,
        total: cls.enrolment,
        status: 'scheduled',
        sync: 'pending',
      }]
    })
    store.addAssessments(created)
    /* Show the year the new records landed in, so they are not filtered out of view. */
    setFilters((f) => ({ ...f, year: Number(draft.date.slice(0, 4)) }))
  }

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">Assessments</h1>
          <p className="mt-1 text-sm font-medium text-muted-foreground">
            {listView === 'queue' ? `${shown} shown` : `${list.length} total`} · {inProgress} in progress
          </p>
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
            className="flex items-center gap-1.5 rounded-xl bg-primary px-3 py-2 text-xs font-bold text-primary-foreground transition-opacity hover:opacity-90"
          >
            <Plus className="size-4" /> New
          </button>
        </div>
      </div>

      {listView === 'queue' ? (
        <QueueList
          assessments={list}
          filters={filters}
          onFiltersChange={setFilters}
          onOpenGrid={(id) => onOpen(id, 'grid')}
          onOpenReport={(id) => onOpen(id, 'report')}
        />
      ) : (
        <BrowseTree
          assessments={list}
          onOpenGrid={(id) => onOpen(id, 'grid')}
          onOpenReport={(id) => onOpen(id, 'report')}
        />
      )}

      <CreateAssessmentDialog
        open={creating}
        onClose={() => {
          setCreating(false)
          onCreateClosed?.()
        }}
        onCreate={create}
      />
    </div>
  )
}

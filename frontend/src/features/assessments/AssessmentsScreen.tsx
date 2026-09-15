import { Plus } from 'lucide-react'
import { useState } from 'react'
import { assessments as seed, type Assessment } from '../../fixtures/assessments'
import { classes } from '../../fixtures/classes'
import { DEFAULT_FILTERS, filterAssessments, type QueueFilters } from '../../lib/assessmentQueue'
import { AssessmentPlaceholder } from './AssessmentPlaceholder'
import { BrowseTree } from './BrowseTree'
import { CreateAssessmentDialog, type NewAssessment } from './CreateAssessmentDialog'
import { QueueList } from './QueueList'

type View = 'queue' | 'browse'

type AssessmentsScreenProps = {
  /* Set when the user is inside one assessment; undefined on the list. */
  assessmentId?: string
  view?: 'grid' | 'report'
  onOpen: (assessmentId: string, view: 'grid' | 'report') => void
  onBackToList: () => void
}

export function AssessmentsScreen({ assessmentId, view, onOpen, onBackToList }: AssessmentsScreenProps) {
  const [list, setList] = useState<Assessment[]>(seed)
  const [listView, setListView] = useState<View>('queue')
  const [filters, setFilters] = useState<QueueFilters>(DEFAULT_FILTERS)
  const [creating, setCreating] = useState(false)

  const open = assessmentId ? list.find((a) => a.id === assessmentId) : undefined
  if (open && view) {
    return <AssessmentPlaceholder assessment={open} view={view} onBack={onBackToList} />
  }

  const shown = filterAssessments(list, filters).length
  const inProgress = list.filter((a) => a.status === 'in-progress').length

  /* One scheduled assessment per chosen stream. Ids are placeholders for the
     client-generated UUIDs the store will assign. */
  const create = (draft: NewAssessment) => {
    const created: Assessment[] = draft.classIds.flatMap((classId) => {
      const cls = classes.find((c) => c.id === classId)
      if (!cls) return []
      return [{
        id: `new-${classId}-${Date.now()}`,
        subject: draft.subject,
        stream: cls.stream,
        name: draft.name,
        term: 'Term 2',
        year: 2025,
        entered: 0,
        total: cls.enrolment,
        status: 'scheduled',
        sync: 'pending',
      }]
    })
    setList((prev) => [...created, ...prev])
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
          <div className="flex items-center gap-1 rounded-xl border border-border bg-muted/60 p-1" role="tablist">
            {(['queue', 'browse'] as const).map((v) => (
              <button
                key={v}
                type="button"
                role="tab"
                aria-selected={listView === v}
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

      <CreateAssessmentDialog open={creating} onClose={() => setCreating(false)} onCreate={create} />
    </div>
  )
}

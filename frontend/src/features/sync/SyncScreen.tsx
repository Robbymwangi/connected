import { CheckCircle2, TriangleAlert } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { SessionStore } from '../../app/useSessionStore'
import { FilterDropdown } from '../../components/FilterDropdown'
import type { ActiveConflict } from '../../fixtures/conflicts'
import { describeConflictCounts } from '../../lib/conflicts'
import { ActiveConflictCard } from './ActiveConflictCard'
import { HistoricalConflictCard } from './HistoricalConflictCard'

const TABS = ['Active', 'Historical'] as const
type Tab = (typeof TABS)[number]

const HIGHLIGHT_MS = 2500

type SyncScreenProps = {
  store: SessionStore
  /* Conflict to draw the eye to on arrival. */
  highlight?: string
  onOpenGrid: (assessmentId: string) => void
}

export function SyncScreen({ store, highlight, onOpenGrid }: SyncScreenProps) {
  const [tab, setTab] = useState<Tab>('Active')
  const [highlighted, setHighlighted] = useState<string | undefined>(highlight)

  /* The highlight is a moment, not a state: it clears itself. App keys this screen
     on the highlight, so a new one arrives as a fresh mount. */
  useEffect(() => {
    if (!highlight) return
    const timer = setTimeout(() => setHighlighted(undefined), HIGHLIGHT_MS)
    return () => clearTimeout(timer)
  }, [highlight])

  const { groups, history } = store
  const yours = [...groups.needsYou, ...groups.waiting]
  /* A failed read is not "all clear": say so rather than show an empty list as good news. */
  const unreadable = store.loadFailed && yours.length === 0 && groups.others.length === 0

  const card = (c: ActiveConflict) => {
    const assessment = store.assessments.find((item) => item.id === c.assessmentId)
    const criterionMax = assessment
      ? store.criteriaBySubject[assessment.subject]?.find((item) => item.id === c.criterionId)?.max ?? 0
      : 0
    return (
      <ActiveConflictCard
        key={c.id}
        conflict={c}
        criterionMax={criterionMax}
        highlighted={highlighted === c.id}
        onResolve={(choice, note) => store.resolveConflict(c.id, choice, note)}
        onPropose={(choice, note) => store.proposeResolution(c.id, choice, note)}
        onAccept={() => store.acceptProposal(c.id)}
        onRefer={() => store.referConflict(c.id)}
        onOpenGrid={() => onOpenGrid(c.assessmentId)}
      />
    )
  }

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <div className="mb-5 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">Sync</h1>
          <p className="mt-1 text-sm font-medium text-muted-foreground">
            {unreadable ? 'Conflicts could not be read' : describeConflictCounts(groups)}
          </p>
        </div>
        <FilterDropdown label="Show" value={tab} options={TABS} onChange={setTab} />
      </div>

      {tab === 'Active' ? (
        <div className="flex flex-col gap-3">
          {yours.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-4 rounded-2xl border border-border bg-card py-16">
              <div className={`flex size-14 items-center justify-center rounded-full border ${unreadable ? 'border-danger/20 bg-danger/10 text-danger' : 'border-success/20 bg-success/10 text-success'}`}>
                {unreadable ? <TriangleAlert className="size-6" aria-hidden="true" /> : <CheckCircle2 className="size-6" aria-hidden="true" />}
              </div>
              <div className="text-center">
                <p className="text-base font-semibold text-foreground">{unreadable ? 'Conflicts could not be read' : 'All conflicts resolved'}</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  {unreadable ? 'Reload to try again.' : 'Settled items are under Historical.'}
                </p>
              </div>
            </div>
          ) : (
            yours.map(card)
          )}

          {/* Conflicts this person is neither party to nor moderator of: anyone in the school may
              read them, but they are not theirs to settle, so they are not counted and stay folded. */}
          {groups.others.length > 0 && (
            <details className="group mt-2 rounded-2xl border border-border bg-card">
              <summary className="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-muted-foreground marker:content-none hover:text-foreground">
                Other conflicts ({groups.others.length})
              </summary>
              <div className="flex flex-col gap-3 p-3 pt-0">{groups.others.map(card)}</div>
            </details>
          )}
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          {history.length === 0 ? (
            <p className="py-16 text-center text-sm text-muted-foreground">Nothing settled yet.</p>
          ) : (
            history.map((h) => (
              <HistoricalConflictCard key={h.id} conflict={h} />
            ))
          )}
        </div>
      )}
    </div>
  )
}

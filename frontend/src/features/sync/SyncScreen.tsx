import { CheckCircle2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { SessionStore } from '../../app/useSessionStore'
import { FilterDropdown } from '../../components/FilterDropdown'
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

  const { conflicts, history } = store
  const count = conflicts.length
  const subjectOf = (assessmentId: string) =>
    store.assessments.find((a) => a.id === assessmentId)?.subject ?? 'English'

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <div className="mb-5 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">Sync</h1>
          <p className="mt-1 text-sm font-medium text-muted-foreground">
            {count > 0 ? `${count} conflict${count === 1 ? '' : 's'} to resolve` : 'All conflicts resolved'}
          </p>
        </div>
        <FilterDropdown label="Show" value={tab} options={TABS} onChange={setTab} />
      </div>

      {tab === 'Active' ? (
        <div className="flex flex-col gap-3">
          {count === 0 ? (
            <div className="flex flex-col items-center justify-center gap-4 rounded-2xl border border-border bg-card py-16">
              <div className="flex size-14 items-center justify-center rounded-full border border-success/20 bg-success/10 text-success">
                <CheckCircle2 className="size-6" />
              </div>
              <div className="text-center">
                <p className="text-base font-semibold text-foreground">All conflicts resolved</p>
                <p className="mt-1 text-sm text-muted-foreground">Settled items are under Historical.</p>
              </div>
            </div>
          ) : (
            conflicts.map((c) => (
              <ActiveConflictCard
                key={c.id}
                conflict={c}
                subject={subjectOf(c.assessmentId)}
                highlighted={highlighted === c.id}
                onResolve={(choice, note) => store.resolveConflict(c.id, choice, note)}
                onPropose={(choice, note) => store.proposeResolution(c.id, choice, note)}
                onAccept={() => store.acceptProposal(c.id)}
                onRefer={() => store.referConflict(c.id)}
                onOpenGrid={() => onOpenGrid(c.assessmentId)}
              />
            ))
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

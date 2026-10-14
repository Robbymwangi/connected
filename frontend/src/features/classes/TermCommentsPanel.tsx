import { ChevronRight, CloudOff } from 'lucide-react'
import { useState } from 'react'
import { closeTerm } from '../../api/termClosures'
import { useCurrentUser } from '../../app/AuthContext'
import { useConnectivity } from '../../lib/connectivity'
import { pullAfterServerChange } from '../../lib/syncRunner'
import { closable, mayCloseTerm, type TermView } from '../../lib/termComments'
import { Panel } from './Panel'
import { useTermComments } from './useTermComments'

type TermCommentsPanelProps = {
  classId: string
  year: number
  token: string
  onOpenComments: (year: number, term: number) => void
}

const PRIMARY =
  'rounded-xl bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50'

/* The stream's terms for report cards (docs/spec/workflow.md, Term close, Comments): each term
   closed or not, its comments reviewed so far, and its cards issued. Reading works offline;
   closing a term is an online action, offered only to whoever the server would let close it. */
export function TermCommentsPanel({ classId, year, token, onOpenComments }: TermCommentsPanelProps) {
  const user = useCurrentUser()
  const state = useTermComments(user.id, classId, year)
  const { isOnline } = useConnectivity()
  const [closing, setClosing] = useState<number | null>(null)
  const [message, setMessage] = useState<{ term: number; text: string; tone: 'ok' | 'error' } | null>(null)

  if (state.status !== 'ready') {
    return (
      <Panel title="Term comments and report cards">
        <p className="px-5 py-6 text-sm text-muted-foreground" role="status">
          {state.status === 'loading' ? 'Loading…' : 'Term data is unavailable on this device.'}
        </p>
      </Panel>
    )
  }

  const allowed = mayCloseTerm(user)

  const close = async (term: number) => {
    setClosing(term)
    setMessage(null)
    try {
      const { meta } = await closeTerm({ classId, year, term }, token)
      const skipped = meta.skipped ? `, ${meta.skipped} already had one from another stream` : ''
      setMessage({ term, tone: 'ok', text: `Term closed: ${meta.comments} comments requested${skipped}. Updating this device…` })
      /* The close reaches this device by pull; ask for one now rather than at the next tick. */
      pullAfterServerChange(user.id)
    } catch (error) {
      setMessage({ term, tone: 'error', text: error instanceof Error ? error.message : 'The term could not be closed.' })
    } finally {
      setClosing(null)
    }
  }

  return (
    <Panel title="Term comments and report cards" aside={String(year)}>
      <div className="divide-y divide-border/40">
        {state.terms.map((view) => (
          <TermRow
            key={view.term}
            view={view}
            canClose={allowed && closable(view)}
            isOnline={isOnline}
            closing={closing === view.term}
            /* The close's own note gives way to the closed row once the pull has brought it. */
            message={message?.term === view.term && !(message.tone === 'ok' && view.closureId) ? message : null}
            onClose={() => void close(view.term)}
            onOpen={() => onOpenComments(year, view.term)}
          />
        ))}
      </div>
    </Panel>
  )
}

function TermRow({ view, canClose, isOnline, closing, message, onClose, onOpen }: {
  view: TermView
  canClose: boolean
  isOnline: boolean
  closing: boolean
  message: { text: string; tone: 'ok' | 'error' } | null
  onClose: () => void
  onOpen: () => void
}) {
  const accepted = view.comments.filter((comment) => comment.state === 'accepted').length
  const total = view.comments.length

  return (
    <div className="px-5 py-3">
      {view.closureId ? (
        <button type="button" onClick={onOpen} className="group flex w-full items-center gap-3 text-left">
          <span className="min-w-0 flex-1">
            <span className="block text-sm font-semibold text-foreground">Term {view.term}: closed</span>
            <span className="block text-xs text-muted-foreground">
              {total === 0 ? 'Comments arrive with the next sync' : `${accepted} of ${total} comments accepted · ${view.reportsIssued} cards issued`}
            </span>
          </span>
          <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground" />
        </button>
      ) : (
        <div className="flex flex-wrap items-center gap-3">
          <span className="min-w-0 flex-1">
            <span className="block text-sm font-semibold text-foreground">Term {view.term}: open</span>
            <span className="block text-xs text-muted-foreground">
              {view.assessments === 0 ? 'No assessments yet' : `${view.finalized} of ${view.assessments} assessments finalized`}
            </span>
          </span>
          {canClose && (
            isOnline ? (
              <button type="button" className={PRIMARY} disabled={closing} onClick={onClose}>
                {closing ? 'Closing…' : 'Close term'}
              </button>
            ) : (
              <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <CloudOff className="size-3.5" /> Closing a term needs a connection
              </span>
            )
          )}
        </div>
      )}
      {message && (
        <p className={`mt-2 text-xs ${message.tone === 'error' ? 'text-danger' : 'text-muted-foreground'}`} role={message.tone === 'error' ? 'alert' : 'status'}>
          {message.text}
        </p>
      )}
    </div>
  )
}

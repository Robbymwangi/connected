import { useState } from 'react'
import { useCurrentUser } from '../../app/AuthContext'
import { BackNav } from '../../components/BackNav'
import { Modal } from '../../components/Modal'
import { StatusPill, type StatusTone } from '../../components/StatusPill'
import type { SchoolClass } from '../../fixtures/classes'
import type { Student } from '../../fixtures/students'
import type { Teacher } from '../../fixtures/teachers'
import { COMMENT_BODY_LIMIT, CommentMovedError, commentBodyProblem, enqueueCommentCommand, type CommentCommand } from '../../lib/commentCommands'
import { localDatabaseFor } from '../../lib/localDatabase'
import { actionsFor, mayWriteRemarks, type CommentView } from '../../lib/termComments'
import { useTermComments } from './useTermComments'

type CommentReviewProps = {
  cls: SchoolClass
  year: number
  term: number
  students: Student[]
  teachers: Teacher[]
  onBack: () => void
  onBackToList: () => void
}

const STATE: Record<CommentView['state'], { label: string; tone: StatusTone }> = {
  pending: { label: 'Being drafted', tone: 'neutral' },
  draft: { label: 'Draft', tone: 'info' },
  failed: { label: 'Not drafted', tone: 'danger' },
  accepted: { label: 'Accepted', tone: 'success' },
}

const ACTION = 'rounded-xl border px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50'
const SECONDARY = `${ACTION} border-border bg-card text-foreground hover:bg-muted`
const PRIMARY = `${ACTION} border-primary bg-primary text-primary-foreground hover:opacity-90`
const INPUT = 'w-full rounded-xl border border-border bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-primary/30 focus:outline-none'

/* A stream's comments for one closed term (docs/spec/workflow.md, Comments), one per student.
   Reached from the stream, never from the sidebar. Every action is an outbox command, so the
   screen works offline like the marking grid: it shows the command at once and sends it when
   it can. Accepting is final until an unlock and issues the card, so it asks first; there is
   no "accept all". */
export function CommentReview({ cls, year, term, students, teachers, onBack, onBackToList }: CommentReviewProps) {
  const user = useCurrentUser()
  const state = useTermComments(user.id, cls.id, year)
  const [accepting, setAccepting] = useState<CommentView | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const nameOf = (studentId: string) => students.find((student) => student.id === studentId)?.name ?? 'A student'
  const authorOf = (userId: string | null) => (userId === user.id ? 'you' : teachers.find((teacher) => teacher.id === userId)?.name ?? null)

  const send = async (comment: CommentView, command: CommentCommand) => {
    setNotice(null)
    try {
      await enqueueCommentCommand(localDatabaseFor(user.id), comment.id, comment.version, command, new Date().toISOString())
      return true
    } catch (error) {
      setNotice(error instanceof CommentMovedError ? 'That comment changed on this device; it now shows what is current.' : 'The change could not be saved on this device.')
      return false
    }
  }

  const view = state.status === 'ready' ? state.terms.find((item) => item.term === term) : undefined
  const comments = [...(view?.comments ?? [])].sort((a, b) => nameOf(a.studentId).localeCompare(nameOf(b.studentId)))
  const accepted = comments.filter((comment) => comment.state === 'accepted').length
  const canWrite = mayWriteRemarks(user, cls.teacherId)
  const owner = cls.teacherId ? `its class teacher, ${cls.teacher},` : 'its class teacher'

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <BackNav
        onBack={onBack}
        items={[{ label: 'Classes', onClick: onBackToList }, { label: `Stream ${cls.stream}`, onClick: onBack }, { label: `Term ${term} comments` }]}
      />
      <div className="mb-5">
        <h1 className="font-display text-2xl font-bold tracking-tight text-foreground">Term {term} comments</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Stream {cls.stream} · {year} · {accepted} of {comments.length} accepted
        </p>
      </div>
      {!canWrite && comments.length > 0 && (
        <p className="mb-4 rounded-xl border border-border bg-muted/40 px-4 py-2 text-sm text-muted-foreground" data-testid="remarks-owner">
          Remarks for Stream {cls.stream} are written by {owner} or the Director of Studies.
        </p>
      )}
      {notice && <p className="mb-4 rounded-xl border border-warning/30 bg-warning/10 px-4 py-2 text-sm text-foreground" role="status">{notice}</p>}

      {state.status === 'loading' && <p className="text-sm text-muted-foreground" role="status">Loading…</p>}
      {state.status === 'error' && <p className="text-sm text-danger" role="alert">Comments are unavailable on this device.</p>}
      {state.status === 'ready' && !view?.closureId && (
        <p className="text-sm text-muted-foreground">Term {term} is not closed for this stream yet.</p>
      )}
      {state.status === 'ready' && view?.closureId && comments.length === 0 && (
        <p className="text-sm text-muted-foreground">The comments arrive with the next sync.</p>
      )}

      <div className="flex flex-col gap-3">
        {comments.map((comment) => (
          <CommentCard
            key={comment.id}
            comment={comment}
            student={nameOf(comment.studentId)}
            author={authorOf(comment.authorId)}
            onSend={(command) => send(comment, command)}
            onAccept={() => setAccepting(comment)}
            userId={user.id}
            canWrite={canWrite}
          />
        ))}
      </div>

      <Modal
        open={accepting !== null}
        onClose={() => setAccepting(null)}
        title="Accept and issue the card?"
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <button type="button" className={SECONDARY} onClick={() => setAccepting(null)}>Not yet</button>
            <button
              type="button"
              className={PRIMARY}
              onClick={() => {
                const comment = accepting
                setAccepting(null)
                if (comment) void send(comment, { kind: 'accept', byId: user.id })
              }}
            >
              Accept
            </button>
          </div>
        }
      >
        <p className="text-sm leading-relaxed text-foreground">
          {accepting ? nameOf(accepting.studentId) : ''}'s comment becomes final and their Term {term} report card is generated. Only an
          unlock by the Director of Studies or the subject's head of department can change it after that.
        </p>
      </Modal>
    </div>
  )
}

function CommentCard({ comment, student, author, userId, canWrite, onSend, onAccept }: {
  comment: CommentView
  canWrite: boolean
  student: string
  author: string | null
  userId: string
  onSend: (command: CommentCommand) => Promise<boolean>
  onAccept: () => void
}) {
  const [editing, setEditing] = useState(false)
  const [text, setText] = useState('')
  const actions = canWrite ? actionsFor(comment) : []
  const problem = commentBodyProblem(text)
  const length = [...text.trim()].length

  const startEditing = () => {
    setText(comment.standIn || comment.body === null ? '' : comment.body)
    setEditing(true)
  }

  return (
    <article className="rounded-2xl border border-border bg-card px-5 py-4 shadow-sm">
      <header className="mb-2 flex flex-wrap items-center gap-2">
        <h2 className="flex-1 text-sm font-semibold text-foreground">{student}</h2>
        {comment.local && <StatusPill tone="warning" size="sm">Waiting to sync</StatusPill>}
        <StatusPill tone={STATE[comment.state].tone} size="sm">{STATE[comment.state].label}</StatusPill>
      </header>

      {editing ? (
        <div className="flex flex-col gap-2">
          <textarea
            value={text}
            onChange={(event) => setText(event.target.value)}
            rows={4}
            aria-label={`Comment for ${student}`}
            className={`${INPUT} resize-y`}
          />
          <div className="flex flex-wrap items-center gap-2">
            <span className={`flex-1 text-xs ${length > COMMENT_BODY_LIMIT ? 'text-danger' : 'text-muted-foreground'}`}>
              {length} of {COMMENT_BODY_LIMIT} characters
            </span>
            <button type="button" className={SECONDARY} onClick={() => setEditing(false)}>Cancel</button>
            <button
              type="button"
              className={PRIMARY}
              disabled={problem !== null}
              title={problem ?? undefined}
              onClick={() => void onSend({ kind: 'edit', byId: userId, body: text }).then((queued) => { if (queued) setEditing(false) })}
            >
              Save as draft
            </button>
          </div>
        </div>
      ) : (
        <>
          {comment.body ? (
            <p className="text-sm leading-relaxed whitespace-pre-line text-foreground">{comment.body}</p>
          ) : (
            <p className="text-sm text-muted-foreground italic">
              {comment.state === 'failed' ? 'A draft could not be written. Write it yourself or try again.' : 'A draft is being written.'}
            </p>
          )}
          {comment.standIn && (
            <p className="mt-1 text-xs text-muted-foreground">A templated stand-in: no model key was set when it was drafted.</p>
          )}
          {author && comment.state !== 'pending' && <p className="mt-1 text-xs text-muted-foreground">Last written by {author}</p>}
          {comment.refused && <p className="mt-1 text-xs text-danger" role="alert">Your last change was refused: {comment.refused}</p>}
          {actions.length > 0 && (
            <div className="mt-3 flex flex-wrap gap-2">
              {actions.includes('edit') && (
                <button type="button" className={SECONDARY} onClick={startEditing}>{comment.body ? 'Edit' : 'Write'}</button>
              )}
              {actions.includes('regenerate') && comment.state !== 'pending' && (
                <button type="button" className={SECONDARY} onClick={() => void onSend({ kind: 'regenerate', byId: userId })}>
                  {comment.state === 'failed' ? 'Try again' : 'Redraft'}
                </button>
              )}
              {actions.includes('accept') && (
                <button type="button" className={PRIMARY} onClick={onAccept}>Accept</button>
              )}
            </div>
          )}
        </>
      )}
    </article>
  )
}

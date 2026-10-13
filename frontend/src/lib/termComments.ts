import { canApply, overlayCommentRow, type CommentCommand, type CommentState } from './commentCommands'
import type { LocalRecord } from './localDatabase'
import type { OutboxEntry } from './outbox'
import { DIRECTOR_OF_STUDIES } from './session'

/* What the device knows about a stream's terms for comments and report cards: whether each
   term is closed, whether it can be closed, and each student's comment as this device shows
   it (the pulled row with this device's commands applied). Pure, so it is tested without a
   database; useTermComments feeds it from Dexie. */

export const TERMS = [1, 2, 3] as const

export type CommentView = {
  id: string
  studentId: string
  state: CommentState
  body: string | null
  authorId: string | null
  /* The version a new command is sent against. */
  version: number
  /* A command of this device is still on its way, or answered and not yet pulled. */
  local: boolean
  /* The last command of this device on the comment was refused, with why. */
  refused: string | null
  standIn: boolean
}

export type TermView = {
  term: number
  closureId: string | null
  assessments: number
  finalized: number
  comments: CommentView[]
  reportsIssued: number
}

const FINALIZED = new Set(['finalized', 'reports-generated'])
const STAND_IN = '[Stand-in]'
const live = (row: LocalRecord) => row.deletedAt == null
const isState = (value: unknown): value is CommentState =>
  value === 'pending' || value === 'draft' || value === 'accepted' || value === 'failed'

export function commentView(row: LocalRecord, entries: readonly OutboxEntry[]): CommentView | null {
  const mine = entries.filter((entry) => entry.table === 'comments' && entry.recordId === row.id)
  const { row: shown, local, version } = overlayCommentRow(row, mine)
  if (!isState(shown.state) || typeof shown.studentId !== 'string') return null
  const last = mine.filter((entry) => entry.kind === 'command').sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0)).at(-1)
  const body = typeof shown.body === 'string' ? shown.body : null

  return {
    id: row.id,
    studentId: shown.studentId,
    state: shown.state,
    body,
    authorId: typeof shown.authorId === 'string' ? shown.authorId : null,
    version,
    local,
    refused: last?.state === 'failed' ? (last.reason ?? 'refused') : null,
    standIn: body?.startsWith(STAND_IN) ?? false,
  }
}

export function termViews(input: {
  classId: string
  year: number
  assessments: readonly LocalRecord[]
  closures: readonly LocalRecord[]
  comments: readonly LocalRecord[]
  reports: readonly LocalRecord[]
  outbox: readonly OutboxEntry[]
}): TermView[] {
  const { classId, year } = input

  return TERMS.map((term) => {
    const sittings = input.assessments.filter((row) => live(row) && row.classId === classId && row.year === year && row.term === term)
    const closure = input.closures.find((row) => live(row) && row.classId === classId && row.year === year && row.term === term)
    const comments = closure
      ? input.comments
        .filter((row) => live(row) && row.termClosureId === closure.id)
        .flatMap((row) => commentView(row, input.outbox) ?? [])
      : []
    const students = new Set(comments.map((comment) => comment.studentId))

    return {
      term,
      closureId: closure?.id ?? null,
      assessments: sittings.length,
      finalized: sittings.filter((row) => FINALIZED.has(String(row.status))).length,
      comments,
      reportsIssued: input.reports.filter((row) => live(row) && row.year === year && row.term === term && students.has(String(row.studentId))).length,
    }
  })
}

/* Whether the server would let this user close a term (TermClosurePolicy): a Director of Studies
   only (ADR 0014, decision 5). Neither teaching the stream nor being its class teacher grants it.
   The server checks again; this only decides whether to offer it. */
export function mayCloseTerm(user: { role: string }): boolean {
  return user.role === DIRECTOR_OF_STUDIES
}

/* Whether the server would let this user unlock a finalized assessment of this subject
   (AssessmentPolicy::unlock, ADR 0014, decision 3): a Director of Studies, or the HOD of the
   subject, that is a teacher who moderates it. */
export function mayUnlock(user: { role: string; moderatedSubjectIds: readonly string[] }, subjectId: string | undefined): boolean {
  return user.role === DIRECTOR_OF_STUDIES || (subjectId !== undefined && user.moderatedSubjectIds.includes(subjectId))
}

/* A term can be closed once it has assessments and every one is finalized; the server
   decides, this decides whether the button is live. */
export function closable(view: TermView): boolean {
  return view.closureId === null && view.assessments > 0 && view.finalized === view.assessments
}

/* Whether the server would take a comment command from this user (CommentPushHandler::authorize, ADR 0014,
   decision 6): the stream's class teacher, or a Director of Studies. Everyone else reads. */
export function mayWriteRemarks(user: { id: string; role: string }, classTeacherId: string | null | undefined): boolean {
  return user.role === DIRECTOR_OF_STUDIES || (classTeacherId != null && classTeacherId === user.id)
}

export function actionsFor(comment: CommentView): Array<CommentCommand['kind']> {
  return (['edit', 'accept', 'regenerate'] as const).filter((kind) => canApply(comment.state, kind))
}

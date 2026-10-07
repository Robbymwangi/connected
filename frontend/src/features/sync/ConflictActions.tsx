import { useState } from 'react'
import { useCurrentUser } from '../../app/AuthContext'
import type { ActiveConflict, Choice, Proposal } from '../../fixtures/conflicts'
import { rubricFor, type Subject } from '../../fixtures/rubrics'
import { abilityOf, canRefer, describeReferral, isValidNote, sideOf, type Resolver } from '../../lib/conflicts'
import { formatMark, parseMarkInput, type Mark } from '../../lib/grading'
import { formatDateTime } from '../../lib/time'

type ConflictActionsProps = {
  conflict: ActiveConflict
  subject: Subject
  /* Settles the conflict now (own edits, or a moderator). */
  onResolve: (choice: Choice, note: string) => void
  /* Puts a resolution to the other party. */
  onPropose: (choice: Choice, note: string) => void
  /* Accepts the other party's pending proposal. */
  onAccept: () => void
  /* Hands it to a moderator. */
  onRefer: () => void
  onCancel?: () => void
}

/* The one place the resolution policy (ADR 0002) is turned into controls. Used by
   the Sync card and the marking grid's dialog. Sides are named by value and author,
   never as mine or theirs. */
export function ConflictActions({ conflict, subject, onResolve, onPropose, onAccept, onRefer, onCancel }: ConflictActionsProps) {
  const currentUser = useCurrentUser()
  const user: Resolver = { id: currentUser.id, name: currentUser.fullName, moderatedSubjects: currentUser.moderatedSubjectIds }
  const ability = abilityOf(conflict, user, subject)
  const max = rubricFor(subject).find((c) => c.id === conflict.criterionId)?.max ?? 0

  /* A choice is picked first, then confirmed, with a note where the policy needs
     one. 'corrected' means the mark is still being typed. */
  const [picked, setPicked] = useState<Choice | 'corrected' | null>(null)
  /* A counter-proposal starts from the same three choices as a first proposal. */
  const [countering, setCountering] = useState(false)
  const [text, setText] = useState('')
  const [note, setNote] = useState('')

  const parsed = parseMarkInput(text, max)
  const typedMark: Mark | null = parsed.ok && parsed.mark.kind !== 'empty' ? parsed.mark : null
  const choice: Choice | null =
    picked === 'corrected' ? (typedMark ? { kind: 'corrected', mark: typedMark } : null) : picked

  const settling = ability.kind === 'resolve'
  const noteRequired = ability.kind === 'resolve' ? ability.noteRequired : true
  const canConfirm = choice !== null && (!noteRequired || isValidNote(note))

  const reset = () => {
    setPicked(null)
    setCountering(false)
    setText('')
    setNote('')
  }
  const confirm = () => {
    if (!choice) return
    if (settling) onResolve(choice, note.trim())
    else onPropose(choice, note.trim())
    reset()
  }

  const describe = (c: Choice) => {
    if (c.kind === 'corrected') return `corrected to ${formatMark(c.mark)}`
    const side = sideOf(conflict, c.editId)
    return side ? `${formatMark(side.mark)} (${whose(side)})` : 'an unknown edit'
  }
  const whose = (side: ActiveConflict['mine']) => (side.userId === user.id ? 'your mark' : `${side.who}'s mark`)

  const referLink = canRefer(conflict, user) && (
    <button type="button" onClick={onRefer} className="text-xs font-medium text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">
      Refer to a moderator
    </button>
  )

  if (ability.kind === 'observer') {
    return (
      <div className="px-4 pt-1 pb-4">
        {conflict.proposals.length > 0 && <ProposalThread proposals={conflict.proposals} describe={describe} />}
        <p className="mt-2 text-xs font-medium text-muted-foreground">Between the two markers; nothing for you to do here.</p>
      </div>
    )
  }

  if (ability.kind === 'referred') {
    return (
      <div className="px-4 pt-1 pb-4">
        <ProposalThread proposals={conflict.proposals} describe={describe} />
        <p className="mt-2 text-xs font-medium text-warning">{describeReferral(ability.referral)}</p>
      </div>
    )
  }

  if (ability.kind === 'awaiting') {
    return (
      <div className="px-4 pt-1 pb-4">
        <ProposalThread proposals={conflict.proposals} describe={describe} />
        <div className="mt-2 flex items-center justify-between gap-3">
          <p className="text-xs font-medium text-muted-foreground">Awaiting {otherParty(conflict, user.id)}.</p>
          {referLink}
        </div>
      </div>
    )
  }

  if (picked === null && ability.kind === 'respond' && !countering) {
    return (
      <div className="px-4 pt-1 pb-4">
        <ProposalThread proposals={conflict.proposals} describe={describe} />
        <div className="mt-3 flex flex-wrap gap-2">
          <button type="button" onClick={onAccept} className={`${ACTION} border-success bg-success text-success-foreground hover:opacity-90`}>
            Accept
          </button>
          {ability.canCounter ? (
            <button type="button" onClick={() => setCountering(true)} className={`${ACTION} border-border text-foreground hover:bg-muted`}>
              Counter-propose
            </button>
          ) : (
            <button type="button" onClick={onRefer} className={`${ACTION} border-warning/30 bg-warning/10 text-warning hover:bg-warning/20`}>
              Refer to a moderator
            </button>
          )}
        </div>
        {ability.canCounter && <div className="mt-2">{referLink}</div>}
      </div>
    )
  }

  if (picked === null) {
    const verb = settling ? 'Keep' : 'Propose'
    return (
      <div className="px-4 pt-1 pb-4">
        <div className="flex flex-wrap items-center gap-2">
          <SideButton myId={user.id} side={conflict.mine} label={`${verb} ${formatMark(conflict.mine.mark)}`} onClick={() => setPicked({ kind: 'side', editId: conflict.mine.editId })} />
          <SideButton myId={user.id} side={conflict.theirs} label={`${verb} ${formatMark(conflict.theirs.mark)}`} onClick={() => setPicked({ kind: 'side', editId: conflict.theirs.editId })} />
          <button type="button" onClick={() => setPicked('corrected')} className={`${ACTION} border-info/25 bg-info/10 text-info hover:bg-info/20`}>
            {settling ? 'Enter corrected' : 'Propose corrected'}
          </button>
        </div>
        {!settling && (
          <div className="mt-2 flex items-center gap-3">
            {countering && (
              <button type="button" onClick={reset} className="text-xs font-medium text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">
                Back
              </button>
            )}
            {referLink}
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-2 px-4 pt-1 pb-4">
      {picked === 'corrected' ? (
        <input
          autoFocus
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder={`Corrected mark, 0 to ${max}, or A for absent`}
          aria-label="Corrected mark"
          aria-invalid={!parsed.ok || undefined}
          className={`${INPUT} tabular ${parsed.ok ? 'border-border focus:ring-primary/30' : 'border-danger text-danger focus:ring-danger/30'}`}
        />
      ) : (
        <p className="text-sm text-foreground">
          {settling ? 'Keep' : 'Propose'} <strong>{describe(picked)}</strong>
        </p>
      )}
      <textarea
        autoFocus={picked !== 'corrected'}
        value={note}
        onChange={(e) => setNote(e.target.value)}
        placeholder={noteRequired ? 'Why (required)' : 'Add a note (optional)'}
        aria-label="Resolution note"
        aria-required={noteRequired}
        rows={2}
        className={`${INPUT} resize-none border-border focus:ring-primary/30`}
      />
      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => {
            reset()
            onCancel?.()
          }}
          className="rounded-xl border border-border px-4 py-2 text-xs font-semibold text-muted-foreground transition-colors hover:bg-muted"
        >
          Cancel
        </button>
        <button
          type="button"
          disabled={!canConfirm}
          onClick={confirm}
          className={`${ACTION} border-primary bg-primary text-primary-foreground hover:opacity-90 disabled:opacity-40`}
        >
          {settling ? 'Confirm' : 'Send proposal'}
        </button>
      </div>
    </div>
  )
}

const ACTION = 'flex-1 rounded-xl border px-3 py-2 text-center text-xs font-semibold transition-colors'
const INPUT =
  'w-full rounded-xl border bg-muted/50 px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:ring-2 focus:outline-none'

function otherParty(conflict: ActiveConflict, myId: string): string {
  return conflict.mine.userId === myId ? conflict.theirs.who : conflict.mine.who
}

function SideButton({
  side,
  label,
  onClick,
  myId,
}: {
  side: ActiveConflict['mine']
  label: string
  onClick: () => void
  myId: string
}) {
  return (
    <button type="button" onClick={onClick} className={`${ACTION} border-border text-foreground hover:bg-muted`}>
      <span className="block tabular">{label}</span>
      <span className="block text-[10px] font-medium text-muted-foreground">
        {side.userId === myId ? 'you' : side.who}, {formatDateTime(side.at)}
      </span>
    </button>
  )
}

/* "You propose", "Ms. Akinyi proposes", or the past tense for superseded ones. */
function proposalVerb(proposal: Proposal, pending: boolean, myId: string): string {
  const you = proposal.byId === myId
  if (!pending) return `${you ? 'You' : proposal.by} proposed`
  return you ? 'You propose' : `${proposal.by} proposes`
}

/* Every proposal so far, oldest first; the last is the pending one. Reads the
   signed-in account itself (rather than taking myId as a prop) since it is
   used from two separate parent chains (ActiveConflictCard's observer/
   referred/awaiting branches above, and HistoricalConflictCard) that would
   otherwise both have to thread it through just for this. */
export function ProposalThread({
  proposals,
  describe,
  lastIsPending = true,
}: {
  proposals: Proposal[]
  describe: (c: Choice) => string
  /* False on a settled conflict: nothing is pending there. */
  lastIsPending?: boolean
}) {
  const myId = useCurrentUser().id
  return (
    <div className="flex flex-col gap-2">
      {proposals.map((proposal, i) => {
        const pending = lastIsPending && i === proposals.length - 1
        return (
          <div
            key={`${proposal.byId}-${proposal.at}`}
            className={`rounded-xl border px-3 py-2.5 ${pending ? 'border-info/25 bg-info/[0.06]' : 'border-border/50 bg-muted/30'}`}
          >
            <p className={`text-xs font-semibold ${pending ? 'text-info' : 'text-muted-foreground'}`}>
              {proposalVerb(proposal, pending, myId)} {describe(proposal.choice)}
            </p>
            <p className="mt-1 text-xs leading-relaxed text-foreground/80 italic">"{proposal.note}"</p>
            <p className="mt-1 text-[10px] text-muted-foreground/70">{formatDateTime(proposal.at)}</p>
          </div>
        )
      })}
    </div>
  )
}

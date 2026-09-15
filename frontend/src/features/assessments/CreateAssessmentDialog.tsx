import { Check } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Modal } from '../../components/Modal'
import { classes } from '../../fixtures/classes'
import { rubricFor, subjects, type Subject } from '../../fixtures/rubrics'

export type NewAssessment = {
  subject: Subject
  name: string
  date: string
  classIds: string[]
}

type Draft = {
  subject: Subject | null
  name: string
  date: string
  classIds: string[]
}

const EMPTY: Draft = { subject: null, name: '', date: '', classIds: [] }
const STEPS = 4

type CreateAssessmentDialogProps = {
  open: boolean
  onClose: () => void
  onCreate: (assessment: NewAssessment) => void
}

/* Four steps: subject, name and date, streams, confirmation. Each step must be
   complete before the next; the export let name and date through empty. */
export function CreateAssessmentDialog({ open, onClose, onCreate }: CreateAssessmentDialogProps) {
  const [step, setStep] = useState(0)
  const [draft, setDraft] = useState<Draft>(EMPTY)

  const reset = () => {
    setStep(0)
    setDraft(EMPTY)
  }
  const close = () => {
    onClose()
    /* Reset after the exit animation so the content does not jump mid-fade. */
    setTimeout(reset, 200)
  }

  const canContinue =
    step === 0 ? draft.subject !== null
    : step === 1 ? draft.name.trim() !== '' && draft.date !== ''
    : step === 2 ? draft.classIds.length > 0
    : true

  const create = () => {
    if (!draft.subject) return
    onCreate({ subject: draft.subject, name: draft.name.trim(), date: draft.date, classIds: draft.classIds })
    setStep(3)
  }

  const inputClass =
    'w-full rounded-xl border border-border bg-muted/30 px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/30 focus:outline-none'
  const choiceClass = (selected: boolean) =>
    `flex w-full items-center justify-between rounded-xl border px-4 py-3 text-left text-sm font-medium transition-colors ${
      selected ? 'border-primary/40 bg-primary/8 text-foreground' : 'border-border text-foreground/80 hover:bg-muted/40'
    }`

  return (
    <Modal
      open={open}
      onClose={close}
      title="New Assessment"
      headerExtra={
        <div className="flex gap-1" aria-label={`Step ${step + 1} of ${STEPS}`}>
          {Array.from({ length: STEPS }, (_, i) => (
            <div
              key={i}
              className={`h-1.5 w-5 rounded-full transition-colors ${i <= step ? 'bg-primary' : 'bg-muted'}`}
            />
          ))}
        </div>
      }
      footer={
        <>
          {step > 0 && step < 3 && (
            <button
              type="button"
              onClick={() => setStep((s) => s - 1)}
              className="rounded-xl border border-border px-4 py-2.5 text-sm font-semibold text-muted-foreground transition-colors hover:bg-muted"
            >
              Back
            </button>
          )}
          {step < 2 && (
            <PrimaryButton onClick={() => setStep((s) => s + 1)} disabled={!canContinue}>
              Continue
            </PrimaryButton>
          )}
          {step === 2 && (
            <PrimaryButton onClick={create} disabled={!canContinue}>
              Create
            </PrimaryButton>
          )}
          {step === 3 && <PrimaryButton onClick={close}>Done</PrimaryButton>}
        </>
      }
    >
      {step === 0 && (
        <div className="flex flex-col gap-3">
          <p className="mb-1 text-sm font-semibold text-foreground">Subject</p>
          {subjects.map((s) => (
            <button
              key={s}
              type="button"
              onClick={() => setDraft((d) => ({ ...d, subject: s }))}
              className={choiceClass(draft.subject === s)}
            >
              <span>
                {s}
                <span className="ml-2 text-xs text-muted-foreground">
                  {rubricFor(s).map((c) => c.name).join(', ')}
                </span>
              </span>
            </button>
          ))}
        </div>
      )}

      {step === 1 && (
        <div className="flex flex-col gap-3">
          <p className="mb-1 text-sm font-semibold text-foreground">Name and date</p>
          <input
            value={draft.name}
            onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))}
            placeholder="e.g. CAT 2"
            aria-label="Assessment name"
            className={inputClass}
          />
          <input
            type="date"
            value={draft.date}
            onChange={(e) => setDraft((d) => ({ ...d, date: e.target.value }))}
            aria-label="Assessment date"
            className={inputClass}
          />
        </div>
      )}

      {step === 2 && (
        <div className="flex flex-col gap-3">
          <p className="mb-1 text-sm font-semibold text-foreground">Select streams</p>
          {classes.map((c) => {
            const selected = draft.classIds.includes(c.id)
            return (
              <button
                key={c.id}
                type="button"
                onClick={() =>
                  setDraft((d) => ({
                    ...d,
                    classIds: selected ? d.classIds.filter((id) => id !== c.id) : [...d.classIds, c.id],
                  }))
                }
                aria-pressed={selected}
                className={choiceClass(selected)}
              >
                <span>
                  {c.grade} · Stream {c.stream}
                </span>
                {selected && <Check className="size-4 text-primary" />}
              </button>
            )
          })}
        </div>
      )}

      {step === 3 && draft.subject && (
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          <div className="flex size-14 items-center justify-center rounded-full border border-success/20 bg-success/10 text-success">
            <Check className="size-6" />
          </div>
          <p className="text-base font-bold text-foreground">Assessment created</p>
          <p className="text-sm text-muted-foreground">
            <strong>{draft.name}</strong> · {draft.subject}
            <br />
            {draft.classIds.length} stream{draft.classIds.length !== 1 ? 's' : ''} · {draft.date}
          </p>
        </div>
      )}
    </Modal>
  )
}

function PrimaryButton({
  onClick,
  disabled = false,
  children,
}: {
  onClick: () => void
  disabled?: boolean
  children: ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className="flex-1 rounded-xl bg-primary py-2.5 text-sm font-bold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-40"
    >
      {children}
    </button>
  )
}

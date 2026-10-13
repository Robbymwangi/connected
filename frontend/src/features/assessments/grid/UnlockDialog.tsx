import { useState } from 'react'
import { Modal } from '../../../components/Modal'

type UnlockDialogProps = {
  open: boolean
  onClose: () => void
  /* Resolves when the server has unlocked it; rejects with the server's reason. */
  onConfirm: (note: string) => Promise<void>
}

/* Unlocking reopens a finalized assessment for marking (docs/spec/workflow.md, Unlock). The
   note is optional here and required by the server once report cards exist under its closed
   term; the server's own message says so, since only it knows whether they do. */
export function UnlockDialog({ open, onClose, onConfirm }: UnlockDialogProps) {
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const confirm = async () => {
    setSaving(true)
    setError(null)
    try {
      await onConfirm(note)
      setNote('')
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The assessment could not be unlocked.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Unlock assessment?"
      size="sm"
      footer={
        <>
          <button
            type="button"
            onClick={onClose}
            disabled={saving}
            className="flex-1 rounded-xl border border-border py-2.5 text-sm font-semibold text-muted-foreground transition-colors hover:bg-muted"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={() => void confirm()}
            disabled={saving}
            className="flex-1 rounded-xl bg-warning py-2.5 text-sm font-bold text-warning-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {saving ? 'Unlocking...' : 'Unlock'}
          </button>
        </>
      }
    >
      {error && <p className="mb-3 text-sm text-danger" role="alert">{error}</p>}
      <p className="mb-3 text-sm leading-relaxed text-muted-foreground">
        It reopens for marking and its results are withdrawn. If its term was closed, the close is
        undone with its comments and report cards.
      </p>
      <textarea
        value={note}
        onChange={(e) => setNote(e.target.value)}
        rows={3}
        aria-label="Reason for unlocking"
        placeholder="Why it is being unlocked (required once report cards exist)"
        className="w-full rounded-xl border border-border bg-muted/30 px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/30 focus:outline-none"
      />
    </Modal>
  )
}

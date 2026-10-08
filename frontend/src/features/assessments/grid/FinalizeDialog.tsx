import { Modal } from '../../../components/Modal'
import { useState } from 'react'

type FinalizeDialogProps = {
  open: boolean
  onClose: () => void
  onConfirm: () => Promise<void>
}

export function FinalizeDialog({ open, onClose, onConfirm }: FinalizeDialogProps) {
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const confirm = async () => {
    setSaving(true)
    setError(null)
    try {
      await onConfirm()
    } catch {
      setError('Could not save finalization on this device.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Finalize assessment?"
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
            onClick={confirm}
            disabled={saving}
            className="flex-1 rounded-xl bg-warning py-2.5 text-sm font-bold text-warning-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {saving ? 'Saving...' : 'Finalize'}
          </button>
        </>
      }
    >
      {error && <p className="mb-3 text-sm text-danger" role="alert">{error}</p>}
      <p className="text-sm leading-relaxed text-muted-foreground">
        This locks the grid and queues comment generation for when sync next completes.
        It can be unlocked later with a resolution note.
      </p>
    </Modal>
  )
}

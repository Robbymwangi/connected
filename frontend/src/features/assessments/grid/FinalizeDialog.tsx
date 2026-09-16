import { Modal } from '../../../components/Modal'

type FinalizeDialogProps = {
  open: boolean
  onClose: () => void
  onConfirm: () => void
}

export function FinalizeDialog({ open, onClose, onConfirm }: FinalizeDialogProps) {
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
            className="flex-1 rounded-xl border border-border py-2.5 text-sm font-semibold text-muted-foreground transition-colors hover:bg-muted"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={onConfirm}
            className="flex-1 rounded-xl bg-warning py-2.5 text-sm font-bold text-warning-foreground transition-opacity hover:opacity-90"
          >
            Finalize
          </button>
        </>
      }
    >
      <p className="text-sm leading-relaxed text-muted-foreground">
        This locks the grid and queues comment generation for when sync next completes.
        It can be unlocked later with a resolution note.
      </p>
    </Modal>
  )
}

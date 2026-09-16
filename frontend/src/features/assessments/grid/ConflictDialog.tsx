import { TriangleAlert } from 'lucide-react'
import { Modal } from '../../../components/Modal'
import type { ActiveConflict, Choice } from '../../../fixtures/conflicts'
import type { Subject } from '../../../fixtures/rubrics'
import { ConflictActions } from '../../sync/ConflictActions'
import { ConflictSides } from '../../sync/ConflictSides'

type ConflictDialogProps = {
  conflict: ActiveConflict | null
  subject: Subject
  onResolve: (choice: Choice, note: string) => void
  onPropose: (choice: Choice, note: string) => void
  onAccept: () => void
  onRefer: () => void
  onClose: () => void
}

/* The grid's view of a contested cell: both sides and the same actions as the Sync
   screen, so the policy in ADR 0002 applies here too. */
export function ConflictDialog({ conflict, subject, onResolve, onPropose, onAccept, onRefer, onClose }: ConflictDialogProps) {
  return (
    <Modal
      open={conflict !== null}
      onClose={onClose}
      title="Mark conflict"
      size="sm"
      headerExtra={<TriangleAlert className="size-4 text-danger" />}
    >
      {conflict && (
        <div className="-mx-6 -my-5">
          <p className="px-4 pt-3 text-xs text-muted-foreground">
            {conflict.student} · {conflict.criterion}
          </p>
          <ConflictSides mine={conflict.mine} theirs={conflict.theirs} mineEmphasis="contested" theirsEmphasis="contested" />
          <ConflictActions
            conflict={conflict}
            subject={subject}
            onResolve={(choice, note) => {
              onResolve(choice, note)
              onClose()
            }}
            onPropose={(choice, note) => {
              onPropose(choice, note)
              onClose()
            }}
            onAccept={() => {
              onAccept()
              onClose()
            }}
            onRefer={() => {
              onRefer()
              onClose()
            }}
          />
        </div>
      )}
    </Modal>
  )
}

import { ChevronLeft } from 'lucide-react'
import { Breadcrumb, type Crumb } from './Breadcrumb'

type BackNavProps = {
  onBack: () => void
  items: Crumb[]
}

/* Back button plus breadcrumb, the header of every level-two view. */
export function BackNav({ onBack, items }: BackNavProps) {
  return (
    <div className="mb-4 flex items-center gap-3">
      <button
        type="button"
        onClick={onBack}
        aria-label="Go back"
        className="flex size-8 items-center justify-center rounded-lg border border-border bg-card text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        <ChevronLeft className="size-4" />
      </button>
      <Breadcrumb items={items} />
    </div>
  )
}

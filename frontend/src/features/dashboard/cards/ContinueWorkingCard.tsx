import { ChevronRight, Grid3x3 } from 'lucide-react'
import { lastSession } from '../../../fixtures/dashboard'
import './ContinueWorkingCard.css'

type ContinueWorkingCardProps = {
  onContinue: () => void
}

/* Full-width brand panel. Everything on it is primary-foreground at some opacity so
   the card reads the same in both themes. */
export function ContinueWorkingCard({ onContinue }: ContinueWorkingCardProps) {
  return (
    <div className="continue-card relative overflow-hidden rounded-2xl border border-primary-foreground/18 shadow-lg">
      <div className="relative flex items-center gap-5 px-6 py-6">
        <div className="flex size-13 shrink-0 items-center justify-center rounded-xl border border-primary-foreground/25 bg-primary-foreground/18 text-primary-foreground">
          <Grid3x3 className="size-6" strokeWidth={1.75} />
        </div>

        <div className="min-w-0 flex-1">
          <p className="mb-2 text-[10px] leading-none font-bold tracking-widest text-primary-foreground/60 uppercase">
            Continue where you left off
          </p>
          <p className="truncate font-display text-lg leading-snug font-bold text-primary-foreground">
            {lastSession.label}
          </p>
        </div>

        <button
          type="button"
          onClick={onContinue}
          className="flex shrink-0 items-center gap-1.5 rounded-xl border border-primary-foreground/32 bg-primary-foreground/22 px-4 py-2.5 text-sm font-bold text-primary-foreground transition-colors hover:bg-primary-foreground/32"
        >
          Open
          <ChevronRight className="size-4" />
        </button>
      </div>
    </div>
  )
}

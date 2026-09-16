import { ChevronRight, TriangleAlert } from 'lucide-react'
import type { ActiveConflict } from '../../fixtures/conflicts'

type AttentionBannerProps = {
  conflicts: ActiveConflict[]
  /* With a single conflict, the Sync screen is asked to highlight it. */
  onView: (highlight?: string) => void
}

/* Sticky beneath the top bar so the cards scroll behind it. The whole banner is the
   control. Renders nothing when there are no active conflicts. */
export function AttentionBanner({ conflicts, onView }: AttentionBannerProps) {
  const first = conflicts[0]
  if (!first) return null
  const more = conflicts.length - 1

  return (
    <div className="sticky top-16 z-20 mx-4 mt-3 lg:mx-5">
      <button
        type="button"
        onClick={() => onView(conflicts.length === 1 ? first.id : undefined)}
        className="flex w-full items-center gap-3 rounded-xl border border-danger/20 bg-danger/8 px-4 py-2.5 text-left backdrop-blur-sm transition-colors hover:bg-danger/12 dark:bg-danger/6"
      >
        <TriangleAlert className="size-4 shrink-0 text-danger" />
        <span className="shrink-0 text-xs font-bold tracking-wider text-danger uppercase">
          Needs attention
        </span>
        <span className="hidden truncate text-sm text-foreground/80 sm:block">
          Sync conflict with <strong>{first.assessment}</strong>
          {more > 0 && <span className="font-semibold text-danger"> +{more} more</span>}
        </span>
        <span className="ml-auto flex shrink-0 items-center gap-1 text-sm font-semibold text-danger">
          View <ChevronRight className="size-4" />
        </span>
      </button>
    </div>
  )
}

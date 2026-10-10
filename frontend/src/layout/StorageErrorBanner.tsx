import { TriangleAlert } from 'lucide-react'

/* Shown when the last read of this device's saved data failed. What is on screen is then
   the last thing that was read, which may be out of date or incomplete, and saying
   "all clear" on that basis would be false. */
export function StorageErrorBanner() {
  return (
    <div role="alert" className="mx-4 mt-3 flex items-center gap-3 rounded-xl border border-danger/20 bg-danger/8 px-4 py-2.5 lg:mx-5">
      <TriangleAlert className="size-4 shrink-0 text-danger" aria-hidden="true" />
      <p className="text-sm text-foreground/80">
        <strong className="font-semibold text-danger">Saved data could not be read.</strong>{' '}
        What you see may be out of date or incomplete. Reload to try again.
      </p>
    </div>
  )
}

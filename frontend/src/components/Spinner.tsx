import { Loader2 } from 'lucide-react'

/* A loading indicator with a text label for assistive technology. Motion is
   dropped for people who ask for reduced motion; the label stays. */
export function Spinner({ label, className = '' }: { label: string; className?: string }) {
  return (
    <div role="status" className={`flex flex-col items-center justify-center gap-3 py-16 text-sm text-muted-foreground ${className}`}>
      <Loader2 className="size-6 motion-safe:animate-spin" aria-hidden="true" />
      <span>{label}</span>
    </div>
  )
}

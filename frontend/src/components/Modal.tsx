import { X } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Button } from './Button'

const EXIT_MS = 180

type ModalProps = {
  open: boolean
  onClose: () => void
  title: string
  /* Extra content in the header, between the title and the close button. */
  headerExtra?: ReactNode
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md'
}

/* Centred dialog over a blurred scrim. Stays mounted through its exit animation,
   then unmounts. Esc and a click on the scrim both close it. */
export function Modal({ open, onClose, title, headerExtra, children, footer, size = 'md' }: ModalProps) {
  const [mounted, setMounted] = useState(open)
  /* Opening mounts immediately, adjusted during render; closing waits for the exit
     animation, so that side is a timer. */
  if (open && !mounted) setMounted(true)

  useEffect(() => {
    if (open) return
    const timer = setTimeout(() => setMounted(false), EXIT_MS)
    return () => clearTimeout(timer)
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!mounted) return null
  const phase = open ? 'open' : 'close'

  return (
    <div
      className={`fixed inset-0 z-50 flex items-center justify-center bg-overlay/60 p-4 backdrop-blur-xs dialog-backdrop--${phase}`}
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`w-full overflow-hidden rounded-2xl border border-border bg-card shadow-2xl dialog-panel--${phase} ${
          size === 'sm' ? 'max-w-sm' : 'max-w-md'
        }`}
      >
        <div className="flex items-center justify-between border-b border-border/60 px-6 pt-5 pb-4">
          <h3 className="font-display text-base font-bold text-foreground">{title}</h3>
          <div className="flex items-center gap-3">
            {headerExtra}
            <Button onClick={onClose} aria-label="Close" className="size-7 rounded-lg p-0">
              <X className="mx-auto size-4" />
            </Button>
          </div>
        </div>
        <div className="px-6 py-5">{children}</div>
        {footer && <div className="flex gap-2 px-6 pb-5">{footer}</div>}
      </div>
    </div>
  )
}

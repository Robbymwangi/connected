import { X } from 'lucide-react'
import { useEffect, useEffectEvent, useRef, useState, type ReactNode } from 'react'
import { Button } from './Button'

const EXIT_MS = 180

/* Everything that can hold keyboard focus inside the dialog. */
const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

type ModalProps = {
  open: boolean
  onClose: () => void
  title: string
  /* Extra content in the header, between the title and the close button. */
  headerExtra?: ReactNode
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md'
  /* Classes for a frame around the panel, for a decoration such as the orbit
     ring, which must sit outside the panel's own overflow clipping. */
  frameClassName?: string
}

/* Centred dialog over a blurred scrim. Stays mounted through its exit animation,
   then unmounts. Esc and a click on the scrim both close it. */
export function Modal({ open, onClose, title, headerExtra, children, footer, size = 'md', frameClassName = '' }: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null)
  /* The latest onClose, callable from the effect without being a dependency of it.
     Callers often pass a fresh function each render, and re-running the focus
     effect on every keystroke would yank focus out of the field being typed in. */
  const requestClose = useEffectEvent(() => onClose())
  const [mounted, setMounted] = useState(open)
  /* Opening mounts immediately, adjusted during render; closing waits for the exit
     animation, so that side is a timer. */
  if (open && !mounted) setMounted(true)

  useEffect(() => {
    if (open) return
    const timer = setTimeout(() => setMounted(false), EXIT_MS)
    return () => clearTimeout(timer)
  }, [open])

  /* A dialog takes the keyboard while it is open: focus moves in, Tab cycles within
     it, and focus returns to whatever opened it on close. Without the trap, Tab
     walks off into the page behind the scrim, which is still fully operable.
     See WAI-ARIA Authoring Practices, Dialog (Modal) pattern. */
  useEffect(() => {
    if (!open) return

    const opener = document.activeElement as HTMLElement | null
    /* tabIndex filters out controls opted out of the tab order with tabindex="-1",
       which the selector alone would still match. */
    const focusables = () =>
      Array.from(panelRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []).filter(
        (el) => el.tabIndex >= 0,
      )

    focusables()[0]?.focus()

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        requestClose()
        return
      }
      if (e.key !== 'Tab') return

      const items = focusables()
      if (items.length === 0) return
      const first = items[0]
      const last = items[items.length - 1]
      const active = document.activeElement

      if (e.shiftKey && (active === first || !panelRef.current?.contains(active))) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && active === last) {
        e.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('keydown', onKey)
      opener?.focus()
    }
  }, [open])

  if (!mounted) return null
  const phase = open ? 'open' : 'close'

  return (
    <div
      className={`fixed inset-0 z-50 flex items-center justify-center bg-overlay/60 p-4 backdrop-blur-xs dialog-backdrop--${phase}`}
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <div className={`w-full rounded-2xl dialog-panel--${phase} ${size === 'sm' ? 'max-w-sm' : 'max-w-md'} ${frameClassName}`}>
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className="w-full overflow-hidden rounded-2xl border border-border bg-card shadow-2xl"
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
    </div>
  )
}

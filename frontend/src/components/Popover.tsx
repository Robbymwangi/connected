import { useRef, type ReactNode } from 'react'
import { useClickOutside } from '../lib/useClickOutside'

type Anchor = 'top-left' | 'top-right' | 'bottom-left'

/* Where the panel sits relative to its positioned parent, and which way it slides
   in from. */
const ANCHOR_CLASSES: Record<Anchor, { position: string; closed: string }> = {
  'top-left': {
    position: 'top-[calc(100%+6px)] left-0 origin-top-left',
    closed: '-translate-y-2 scale-[0.97]',
  },
  'top-right': {
    position: 'top-[calc(100%+8px)] right-0 origin-top-right',
    closed: '-translate-y-2 scale-[0.97]',
  },
  'bottom-left': {
    position: 'bottom-0 left-[calc(100%+8px)] origin-bottom-left',
    closed: '-translate-x-2 scale-[0.97]',
  },
}

type PopoverProps = {
  open: boolean
  onClose: () => void
  anchor: Anchor
  className?: string
  children: ReactNode
}

/* Anchored panel that stays mounted and animates in and out, dismissed by a click
   outside it. The parent must be position: relative. */
export function Popover({ open, onClose, anchor, className = '', children }: PopoverProps) {
  const ref = useRef<HTMLDivElement>(null)
  useClickOutside(ref, onClose)
  const { position, closed } = ANCHOR_CLASSES[anchor]

  return (
    <div
      ref={ref}
      aria-hidden={!open}
      className={`absolute z-50 overflow-hidden rounded-2xl border border-border shadow-2xl transition-[opacity,transform] duration-200 ease-out ${position} ${
        open ? 'opacity-100' : `pointer-events-none opacity-0 ${closed}`
      } ${className}`}
    >
      {children}
    </div>
  )
}

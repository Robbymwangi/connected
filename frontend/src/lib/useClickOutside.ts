import { useEffect, type RefObject } from 'react'

/* Calls handler on a pointer-down anywhere outside the referenced element. Used to
   dismiss popovers. mousedown rather than click so the dismissal happens before the
   outside target handles its own click. */
export function useClickOutside<T extends HTMLElement>(
  ref: RefObject<T | null>,
  handler: () => void,
) {
  useEffect(() => {
    const listener = (event: MouseEvent) => {
      if (!ref.current || ref.current.contains(event.target as Node)) return
      handler()
    }
    document.addEventListener('mousedown', listener)
    return () => document.removeEventListener('mousedown', listener)
  }, [ref, handler])
}

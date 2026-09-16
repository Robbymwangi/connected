import { useEffect, type RefObject } from 'react'

type Ref = RefObject<HTMLElement | null>

/* Calls handler on a pointer-down outside every referenced element. mousedown rather
   than click so the dismissal happens before the outside target handles its own
   click.

   Pass the trigger as well as the panel. If the trigger sits outside the boundary,
   its mousedown closes the panel and its own click then toggles it straight back
   open, so clicking an open trigger would never close it. */
export function useClickOutside(refs: Ref | Ref[], handler: () => void) {
  useEffect(() => {
    const list = Array.isArray(refs) ? refs : [refs]
    const listener = (event: MouseEvent) => {
      const target = event.target as Node
      if (list.some((ref) => ref.current?.contains(target))) return
      handler()
    }
    document.addEventListener('mousedown', listener)
    return () => document.removeEventListener('mousedown', listener)
  }, [refs, handler])
}

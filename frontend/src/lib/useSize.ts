import { useEffect, useState, type RefObject } from 'react'

/* The rendered width of an element, kept current by a ResizeObserver, so an SVG
   chart can lay itself out in real pixels and its text stays crisp. */
export function useWidth(ref: RefObject<HTMLElement | null>): number {
  const [width, setWidth] = useState(0)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width))
    observer.observe(el)
    return () => observer.disconnect()
  }, [ref])
  return width
}

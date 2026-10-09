/* The development-only connectivity toggle, held in one place so everything that asks
   "is this device online?" sees the same answer: the pill, and the sync runner. When
   the toggle lived in one hook instance, a demo could show Offline while the runner
   went on pushing. Framework-free; useConnectivity reads it with useSyncExternalStore.
   Production builds never set it (the toggle is undefined outside development). */

let value: boolean | null = null
const listeners = new Set<() => void>()

export const connectivityOverride = {
  get: (): boolean | null => value,
  set(next: boolean | null) {
    if (next === value) return
    value = next
    for (const listener of listeners) listener()
  },
  subscribe(listener: () => void) {
    listeners.add(listener)
    return () => { listeners.delete(listener) }
  },
}

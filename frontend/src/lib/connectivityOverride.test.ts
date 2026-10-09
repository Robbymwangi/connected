import { describe, expect, it, vi } from 'vitest'
import { connectivityOverride } from './connectivityOverride'

describe('connectivityOverride', () => {
  it('holds no override until one is set, and tells subscribers only when it changes', () => {
    const listener = vi.fn()
    const unsubscribe = connectivityOverride.subscribe(listener)
    expect(connectivityOverride.get()).toBeNull()

    connectivityOverride.set(false)
    connectivityOverride.set(false)
    expect(connectivityOverride.get()).toBe(false)
    expect(listener).toHaveBeenCalledTimes(1)

    connectivityOverride.set(null)
    expect(listener).toHaveBeenCalledTimes(2)

    unsubscribe()
    connectivityOverride.set(true)
    expect(listener).toHaveBeenCalledTimes(2)
    connectivityOverride.set(null)
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, ApiError } from './client'

const json = (body: unknown, init?: ResponseInit) =>
  new Response(JSON.stringify(body), { status: 200, ...init })

describe('apiFetch', () => {
  it('sends JSON with Accept and Content-Type when there is a body', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ ok: true }))
    await apiFetch('/api/login', { method: 'POST', body: { email: 'a@b.com' } }, fetchMock)

    const [path, init] = fetchMock.mock.calls[0]
    expect(path).toBe('/api/login')
    expect(init.method).toBe('POST')
    expect(init.body).toBe(JSON.stringify({ email: 'a@b.com' }))
    expect((init.headers as Headers).get('Accept')).toBe('application/json')
    expect((init.headers as Headers).get('Content-Type')).toBe('application/json')
  })

  it('sends no Content-Type and no body when none is given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ ok: true }))
    await apiFetch('/api/me', {}, fetchMock)

    const [, init] = fetchMock.mock.calls[0]
    expect(init.body).toBeUndefined()
    expect((init.headers as Headers).has('Content-Type')).toBe(false)
  })

  it('attaches a bearer token only when one is given', async () => {
    /* A fresh Response per call: its body can only be read once, and apiFetch
       now genuinely reads every 2xx body rather than swallowing a re-read
       failure into null. */
    const fetchMock = vi.fn().mockImplementation(async () => json({ ok: true }))
    await apiFetch('/api/me', { token: 'abc' }, fetchMock)
    expect((fetchMock.mock.calls[0][1].headers as Headers).get('Authorization')).toBe('Bearer abc')

    fetchMock.mockClear()
    await apiFetch('/api/me', { token: null }, fetchMock)
    expect((fetchMock.mock.calls[0][1].headers as Headers).has('Authorization')).toBe(false)
  })

  it('returns the parsed body on a 2xx response', async () => {
    const result = await apiFetch('/api/me', {}, vi.fn().mockResolvedValue(json({ id: '1' })))
    expect(result).toEqual({ id: '1' })
  })

  it('rejects rather than silently returning null when a 2xx response body will not parse', async () => {
    const notJson = new Response('<html>a captive portal, not the API</html>', { status: 200 })
    await expect(apiFetch('/api/me', {}, vi.fn().mockResolvedValue(notJson))).rejects.toThrow()
  })

  it('throws ApiError carrying the status and body on a non-2xx response', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      json({ message: 'These credentials do not match our records.' }, { status: 422 }),
    )
    const error = await apiFetch('/api/login', {}, fetchMock).catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(422)
    expect((error as ApiError).message).toBe('These credentials do not match our records.')
  })

  it('lets a network failure propagate as itself, not an ApiError', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'))
    const error = await apiFetch('/api/me', {}, fetchMock).catch((e: unknown) => e)
    expect(error).not.toBeInstanceOf(ApiError)
    expect(error).toBeInstanceOf(TypeError)
  })

  describe('with a request that never answers', () => {
    beforeEach(() => vi.useFakeTimers())
    afterEach(() => vi.useRealTimers())

    it('gives up after the timeout by aborting the request', async () => {
      const hangs = vi.fn(
        (_url: string, init?: RequestInit) =>
          new Promise<Response>((_resolve, reject) => {
            init?.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
          }),
      )
      const result = apiFetch('/api/me', { timeoutMs: 5_000 }, hangs as unknown as typeof fetch)
      /* Attached before advancing the timers, not after: otherwise the
         rejection has nothing observing it yet at the moment it happens,
         which Node reports as an unhandled rejection even though the next
         line goes on to handle it. */
      const assertion = expect(result).rejects.toMatchObject({ name: 'AbortError' })
      await vi.advanceTimersByTimeAsync(5_000)
      await assertion
    })
  })
})

import { describe, expect, it, vi } from 'vitest'
import type { Session, SessionStorage } from '../lib/session'
import { performSignIn, performSignOut, revalidate, signInErrorMessage } from './authSession'

const json = (body: unknown, init?: ResponseInit) =>
  new Response(JSON.stringify(body), { status: 200, ...init })

const meResponse = {
  id: 'u-1',
  name: 'Mercy Achieng',
  email: 'm.achieng@greenvalley.test',
  is_admin: false,
  moderated_subject_ids: [],
}

function fakeStorage(initial: Session | null = null): SessionStorage & { current: Session | null } {
  return {
    current: initial,
    async load() {
      return this.current
    },
    async save(session) {
      this.current = session
    },
    async clear() {
      this.current = null
    },
  }
}

describe('revalidate', () => {
  const cached: Session = {
    token: 'tok',
    user: { id: 'u-1', firstName: 'M', fullName: 'M', initials: 'M', role: 'Teacher', email: 'old@x.test', moderatedSubjectIds: [] },
  }

  it('refreshes with the current /me on success, and writes nothing itself', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json(meResponse))

    const verdict = await revalidate(cached, fetchMock)

    expect(verdict).toEqual({ kind: 'refresh', user: expect.objectContaining({ email: 'm.achieng@greenvalley.test' }) })
    expect(fetchMock).toHaveBeenCalledWith('/api/me', expect.objectContaining({ headers: expect.any(Headers) }))
  })

  it('signs out on a 401 (the account was deactivated, or the token was revoked)', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ message: 'Unauthenticated.' }, { status: 401 }))
    expect(await revalidate(cached, fetchMock)).toEqual({ kind: 'signOut' })
  })

  it('keeps the cached session when the server cannot be reached at all, not signed out', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'))
    expect(await revalidate(cached, fetchMock)).toEqual({ kind: 'keep' })
  })

  it('keeps the cached session on any other server error too, e.g. a 500', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ message: 'Server error' }, { status: 500 }))
    expect(await revalidate(cached, fetchMock)).toEqual({ kind: 'keep' })
  })
})

describe('performSignIn', () => {
  it('logs in, fetches /me, saves, and returns the session', async () => {
    const storage = fakeStorage(null)
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(json({ token: 'new-token' }))
      .mockResolvedValueOnce(json(meResponse))

    const session = await performSignIn('m.achieng@greenvalley.test', 'password', storage, fetchMock)

    expect(session.token).toBe('new-token')
    expect(session.user.fullName).toBe('Mercy Achieng')
    expect(storage.current).toEqual(session)
  })

  it('rejects and saves nothing when the credentials are wrong', async () => {
    const storage = fakeStorage(null)
    const fetchMock = vi.fn().mockResolvedValue(json({ message: 'no' }, { status: 422 }))

    await expect(performSignIn('x@x.test', 'wrong', storage, fetchMock)).rejects.toThrow()
    expect(storage.current).toBeNull()
  })
})

describe('performSignOut', () => {
  it('clears local storage immediately regardless of whether the API call succeeds', async () => {
    const storage = fakeStorage({
      token: 'tok',
      user: { id: 'u-1', firstName: 'M', fullName: 'M', initials: 'M', role: 'Teacher', email: 'm@x.test', moderatedSubjectIds: [] },
    })
    const fetchMock = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'))

    await performSignOut('tok', storage, fetchMock)

    expect(storage.current).toBeNull()
  })

  it('does not call the API at all with no token to revoke', async () => {
    const storage = fakeStorage(null)
    const fetchMock = vi.fn()
    await performSignOut(undefined, storage, fetchMock)
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('signInErrorMessage', () => {
  it('gives a distinct message for the rate limit, wrong credentials, and unreachable server', async () => {
    const { ApiError } = await import('../api/client')
    expect(signInErrorMessage(new ApiError(429, {}))).toMatch(/too many/i)
    expect(signInErrorMessage(new ApiError(422, {}))).toMatch(/incorrect/i)
    expect(signInErrorMessage(new TypeError('Failed to fetch'))).toMatch(/reach the server/i)
  })
})

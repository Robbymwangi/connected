import { login, logout, me } from '../api/auth'
import { ApiError, type FetchImpl } from '../api/client'
import { toCurrentUser, type Session, type SessionStorage } from '../lib/session'

/* Framework-free session logic (build plan 1.7, #45), like lib/health.ts:
   storage and fetch are parameters, so every path here is testable with a
   mocked fetch and a fake SessionStorage, no browser and no React needed.
   useAuthSession.ts is the thin hook that calls these from state. */

/* Boot happens in two separate steps, not one: showing the cached user must
   never wait on the network, or a slow or hanging /me request (a weak
   signal, a stalling captive portal, not only an outright offline one)
   would leave the screen blank for as long as that request takes. */
export type RevalidateVerdict =
  | { kind: 'refresh'; user: Session['user'] }
  /* The API answering 401 is the only thing that actually signs the user
     out here; anything else the request can throw (a network failure, a
     timeout) means the server was never reached, and must leave the
     cached session exactly as it was; see ApiError's own note on why the
     two are never conflated. */
  | { kind: 'signOut' }
  | { kind: 'keep' }

export async function revalidate(session: Session, fetchImpl: FetchImpl): Promise<RevalidateVerdict> {
  try {
    return { kind: 'refresh', user: toCurrentUser(await me(session.token, fetchImpl)) }
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) return { kind: 'signOut' }
    return { kind: 'keep' }
  }
}

export async function performSignIn(
  email: string,
  password: string,
  storage: SessionStorage,
  fetchImpl: FetchImpl,
): Promise<Session> {
  const token = await login(email, password, fetchImpl)
  const user = toCurrentUser(await me(token, fetchImpl))
  const session: Session = { token, user }
  await storage.save(session)
  return session
}

/* Clears the local session first and waits for that; the server-side
   revocation is a courtesy attempted afterwards and never awaited, so a
   sign-out taken offline still signs the device out locally. */
export async function performSignOut(
  token: string | undefined,
  storage: SessionStorage,
  fetchImpl: FetchImpl,
): Promise<void> {
  await storage.clear()
  if (token) void logout(token, fetchImpl).catch(() => {})
}

export function signInErrorMessage(e: unknown): string {
  if (e instanceof ApiError) {
    if (e.status === 429) return 'Too many attempts. Try again in a minute.'
    if (e.status === 422) return 'Incorrect email or password.'
  }
  return 'Could not reach the server. Check your connection and try again.'
}

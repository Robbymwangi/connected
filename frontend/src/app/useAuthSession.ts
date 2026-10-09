import { useEffect, useEffectEvent, useRef, useState } from 'react'
import type { FetchImpl } from '../api/client'
import { bindSyncRunner } from '../lib/syncRunner'
import { createDexieSessionStorage } from '../lib/sessionStorage'
import type { CurrentUser, Session, SessionStorage } from '../lib/session'
import { performSignIn, performSignOut, revalidate, signInErrorMessage } from './authSession'

export type AuthState =
  | { status: 'loading' }
  | {
      status: 'signedOut'
      signingIn: boolean
      error: string | null
      signIn: (email: string, password: string) => Promise<void>
    }
  | { status: 'signedIn'; user: CurrentUser; signOut: () => Promise<void> }

/* One stable adapter for the app's real usage; Dexie manages its database
  connection, so there is no per-instance state worth recreating on every
  render. Tests pass their own fake storage to
   authSession.ts's functions directly instead of going through this hook,
   the same way lib/connectivity.ts's useConnectivity hook is untested in
   Vitest and its framework-free logic (lib/health.ts) is. */
const defaultStorage = createDexieSessionStorage()

/* Whose identity has been verified, and so what the sync runner may act as. A cached
   session is shown at once but is not trusted to sync until /api/me has answered (or
   the server could not be reached, which keeps it): otherwise a cached token that now
   belongs to someone else would push and pull into the wrong person's database. */
type SyncIdentity = { token: string; userId: string }

/* The thin React wrapper around app/authSession.ts (build plan 1.7, #45).
   Boot is two steps, not one: the cached session is shown the instant it
   loads, and /api/me's revalidation of it runs after, in the background, so
   a slow or hanging request never leaves the screen blank. */
export function useAuthSession(
  storage: SessionStorage = defaultStorage,
  fetchImpl: FetchImpl = fetch,
): AuthState {
  const [session, setSession] = useState<Session | null | undefined>(undefined)
  const [signingIn, setSigningIn] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [syncIdentity, setSyncIdentity] = useState<SyncIdentity | null>(null)

  /* Which token the visible session actually belongs to, so a revalidation
     started against one session cannot land after a sign-out or a different
     sign-in has already replaced it: a late 200 must not resurrect the old
     session over a newer one, and a late 401 must not sign out a session
     that already changed. Read fresh at apply time, not captured by the
     effect's closure. */
  const currentToken = useRef<string | null>(null)

  useEffect(() => {
    let cancelled = false

    void storage.load().then((cached) => {
      if (cancelled) return
      setSession(cached)
      currentToken.current = cached?.token ?? null
      if (!cached) return

      void revalidate(cached, fetchImpl).then((verdict) => {
        if (cancelled || currentToken.current !== cached.token) return

        if (verdict.kind === 'refresh') {
          const next: Session = { token: cached.token, user: verdict.user }
          setSession(next)
          void storage.save(next)
          setSyncIdentity({ token: cached.token, userId: verdict.user.id })
        } else if (verdict.kind === 'signOut') {
          currentToken.current = null
          setSession(null)
          void storage.clear()
        } else {
          // 'keep': the cached session shown at the start of this effect is
          // already correct, and the server was not reached to say otherwise, so
          // it syncs its own outbox once the link is back.
          setSyncIdentity({ token: cached.token, userId: cached.user.id })
        }
      })
    })

    return () => {
      cancelled = true
    }
    // storage and fetchImpl are fixed for the life of the hook (both default
    // to a stable value the caller holds), so this runs once.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  /* The runner follows the verified identity: it starts when one is set, restarts when
     the token or user changes, and stops when it is cleared. A 401 on any request it
     makes ends the session the same way a 401 from /api/me does. */
  const endSession = useEffectEvent((token: string) => {
    if (currentToken.current !== token) return
    currentToken.current = null
    setSyncIdentity(null)
    setSession(null)
    void storage.clear()
  })
  const syncToken = syncIdentity?.token ?? null
  const syncUserId = syncIdentity?.userId ?? null

  useEffect(() => {
    if (!syncToken || !syncUserId) return
    return bindSyncRunner({ token: syncToken, userId: syncUserId, fetchImpl, onReauth: endSession })
    // fetchImpl is fixed for the life of the hook, as for the boot effect above.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [syncToken, syncUserId])

  async function signIn(email: string, password: string): Promise<void> {
    setSigningIn(true)
    setError(null)
    try {
      const next = await performSignIn(email, password, storage, fetchImpl)
      currentToken.current = next.token
      setSession(next)
      setSyncIdentity({ token: next.token, userId: next.user.id })
    } catch (e) {
      setError(signInErrorMessage(e))
    } finally {
      setSigningIn(false)
    }
  }

  async function signOut(): Promise<void> {
    const token = session?.token
    currentToken.current = null
    setSyncIdentity(null)
    await performSignOut(token, storage, fetchImpl)
    setSession(null)
  }

  if (session === undefined) return { status: 'loading' }
  if (session === null) return { status: 'signedOut', signingIn, error, signIn }
  return { status: 'signedIn', user: session.user, signOut }
}

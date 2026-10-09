import { createContext, useContext } from 'react'
import type { CurrentUser } from '../lib/session'

/* The signed-in user and how to sign out, for the handful of components that
   need it but do not otherwise receive it as a prop (build plan 1.7, #45):
   Sidebar's footer, Dashboard's greeting, ReportsScreen, and the conflict-
   resolution controls, which sit two or three layers below three separate
   parent chains (SyncScreen, and the marking grid's ConflictDialog).

   The one Context in this codebase; everything else is plain props. Chosen
   over prop-drilling here because those chains are separate and it is one
   rarely-changing value (once per sign-in or sign-out, which already
   remounts this whole tree), not because Context is faster: it is not, and
   for a value that changed often with many readers, props would be the
   better choice. Chosen over a state-management library because that would
   be solving a problem this app does not have yet; if more cross-cutting
   state shows up later, that is its own decision, with its own ADR. */
export type AuthContextValue = {
  user: CurrentUser
  signOut: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

function useAuthContext(): AuthContextValue {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useCurrentUser/useSignOut used outside AuthProvider')
  return value
}

export function useCurrentUser(): CurrentUser {
  return useAuthContext().user
}

export function useSignOut(): () => void {
  return useAuthContext().signOut
}

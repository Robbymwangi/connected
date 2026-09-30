import type { MeResponse } from '../api/auth'

/* Replaces fixtures/user.ts (build plan 1.7, #45): the same shape, so every
   component that read the fixture keeps working unchanged, but the values
   now come from a real signed-in account. */
export type CurrentUser = {
  id: string
  firstName: string
  fullName: string
  initials: string
  role: string
  email: string
  /* The API returns which subjects an account moderates, a list, because
     moderation is scoped per subject (docs/spec/access-model.md, Roles). This
     flattens it to whether it moderates any of them at all, which is what the
     fixture-driven conflict flow (still fixture data until the sync layer
     lands) has ever checked. The real, per-assessment-subject check is that
     flow's job once conflicts are real rows, not this mapping's. */
  canModerate: boolean
}

export type Session = { token: string; user: CurrentUser }

export function toCurrentUser(me: MeResponse): CurrentUser {
  const [firstName] = me.name.trim().split(/\s+/)
  return {
    id: me.id,
    firstName: firstName ?? me.name,
    fullName: me.name,
    initials: initialsOf(me.name),
    /* No role string exists server-side, on purpose (docs/spec/access-model.md:
       capabilities are layered on an account, not one exclusive role). Every
       account grades unrestricted; is_admin is the one flag worth naming here. */
    role: me.is_admin ? 'Administrator' : 'Teacher',
    email: me.email,
    canModerate: me.moderated_subject_ids.length > 0,
  }
}

function initialsOf(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  const first = parts[0]?.[0] ?? ''
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : ''
  return (first + last).toUpperCase()
}

/* Behind this interface, not called directly, so the local store (3.3) can
   become the real implementation without any caller changing. */
export type SessionStorage = {
  load: () => Promise<Session | null>
  save: (session: Session) => Promise<void>
  clear: () => Promise<void>
}

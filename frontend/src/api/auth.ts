import { apiFetch, type FetchImpl } from './client'

/* The API's actual /me shape (api/app/Http/Controllers/MeController.php, #43):
   moderation is a list of subject ids, never a flattened boolean, because it
   is scoped per subject (docs/spec/access-model.md, Roles). Flattening it is
   this ticket's job, in lib/session.ts, not the API's. */
export type MeResponse = {
  id: string
  name: string
  email: string
  is_admin: boolean
  moderated_subject_ids: string[]
}

type LoginResponse = { token: string }

export async function login(
  email: string,
  password: string,
  fetchImpl: FetchImpl = fetch,
): Promise<string> {
  const { token } = await apiFetch<LoginResponse>(
    '/api/login',
    { method: 'POST', body: { email, password } },
    fetchImpl,
  )
  return token
}

export async function me(token: string, fetchImpl: FetchImpl = fetch): Promise<MeResponse> {
  return apiFetch<MeResponse>('/api/me', { token }, fetchImpl)
}

export async function logout(token: string, fetchImpl: FetchImpl = fetch): Promise<void> {
  await apiFetch<{ ok: boolean }>('/api/logout', { method: 'POST', token }, fetchImpl)
}

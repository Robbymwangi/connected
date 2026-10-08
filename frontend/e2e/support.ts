import { expect, type Page } from '@playwright/test'

/* Shared by every e2e spec that needs a signed-in app (build plan 1.7, #45):
   the app now gates everything behind sign-in, and none of these specs run
   against a real API, so there is no real /api/login or /api/me to reach. */
export const E2E_USER = {
  id: 'e2e-teacher',
  name: 'Jane Teacher',
  email: 'jane@example.test',
  is_admin: false,
  moderated_subject_ids: [] as string[],
}

export type E2ESyncChange = {
  table: string
  recordId: string
  version: number
  fields: Record<string, unknown>
}

/* Fakes login, /me, and the initial sync pull just long enough to sign in and
  populate IndexedDB, then removes the routes. Later reloads and offline
  checks depend on local data or the real network, never on these fakes. */
export async function signIn(page: Page, syncChanges: E2ESyncChange[] = []) {
  await page.route('**/api/login', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ token: 'e2e-token' }) }),
  )
  await page.route('**/api/me', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(E2E_USER) }),
  )
  await page.route('**/api/sync**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ changes: syncChanges, cursor: 1, more: false }),
    }),
  )

  const syncResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/sync')
  await page.goto('/')
  await page.getByLabel('Email').fill(E2E_USER.email)
  await page.getByLabel('Password').fill('whatever')
  await page.getByRole('button', { name: 'Sign in' }).click()

  /* The dashboard's own greeting, specifically: the sign-in form has a
     level-1 heading too ("Sign in"), so an untargeted heading check could
     resolve against that instead, before the async sign-in has actually
     finished. The sidebar is not a safe signal either; it starts collapsed
     off-screen (aria-hidden, inert) until opened. */
  await expect(page.getByRole('heading', { level: 1, name: /morning|afternoon|evening/i })).toBeVisible()
  await page.waitForFunction(() => navigator.serviceWorker.controller !== null)
  await syncResponse

  await page.unroute('**/api/login')
  await page.unroute('**/api/me')
  await page.unroute('**/api/sync**')
}

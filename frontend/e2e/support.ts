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

/* Fakes /api/login and /api/me just long enough to sign in and populate the
   cached session (lib/sessionStorage.ts, IndexedDB), then removes both
   routes again. Whatever a test does after this call (a reload, a fresh
   tab, going offline) depends on the cached session or the real network,
   never on this fake quietly still answering underneath it. */
export async function signIn(page: Page) {
  await page.route('**/api/login', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ token: 'e2e-token' }) }),
  )
  await page.route('**/api/me', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(E2E_USER) }),
  )

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

  await page.unroute('**/api/login')
  await page.unroute('**/api/me')
}

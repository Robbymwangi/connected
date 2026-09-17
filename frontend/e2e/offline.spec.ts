import { expect, test, type Page } from '@playwright/test'

/* The offline guarantee across the cases that broke the hand-written worker and
   drove the move to Workbox (ADR 0004): first visit, then a reload and a deep link
   with no network. Each test uses its own context so one test's worker and caches
   do not leak into the next. */

async function loadOnceOnline(page: Page) {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  /* Wait until a service worker controls the page, so the cache is populated. */
  await page.waitForFunction(() => navigator.serviceWorker.controller !== null)
}

test('reload with no network keeps the app running', async ({ page, context }) => {
  await loadOnceOnline(page)
  await context.setOffline(true)
  await page.reload()
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/morning|afternoon|evening/i)
  await expect(page.getByRole('main')).toBeVisible()
})

test('a deep link opens offline in a fresh tab', async ({ page, context }) => {
  await loadOnceOnline(page)
  await context.setOffline(true)
  const tab = await context.newPage()
  await tab.goto('/reports')
  await expect(tab.getByRole('heading', { level: 1 })).toHaveText('Reports')
})

test('the marking grid opens offline by URL', async ({ page, context }) => {
  await loadOnceOnline(page)
  await context.setOffline(true)
  await page.goto('/assessments/a1/grid')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('English')
})

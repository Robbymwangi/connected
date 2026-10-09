import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import { fakeSyncServer, signIn } from './support'

/* The build-plan 3.4 done-when, in a real browser: take the app offline, edit across
   several screens, bring it back online, and it reconciles with no one clicking
   anything. The API is faked (support.ts): the point is what the app sends, in what
   order, and what the display says, not the server. */

const outboxCount = (page: Page) => page.evaluate(async () => {
  const database = await new Promise<IDBDatabase>((resolve, reject) => {
    const request = indexedDB.open('connected-user-e2e-teacher')
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
  const count = await new Promise<number>((resolve, reject) => {
    const request = database.transaction('outbox').objectStore('outbox').count()
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
  database.close()
  return count
})

async function editOffline(page: Page, context: BrowserContext) {
  await signIn(page)
  await page.route('**/api/health', (route) => route.abort())
  await context.setOffline(true)

  /* Screen one: a mark on an assessment the device already holds. */
  await page.goto('/assessments/a1/grid')
  await page.getByRole('button', { name: 'Edit' }).click()
  await page.getByLabel('Mark out of 20').fill('17')

  /* Screen two: a new assessment, then its own grid, then finalize it. */
  await page.getByRole('button', { name: 'Open navigation' }).click()
  await page.getByRole('complementary', { name: 'Main navigation' }).getByRole('button', { name: 'Assessments', exact: true }).click()
  await page.getByRole('button', { name: 'New' }).click()
  const dialog = page.getByRole('dialog', { name: 'New Assessment' })
  await dialog.getByRole('button', { name: /English/ }).click()
  await dialog.getByRole('button', { name: 'Continue' }).click()
  await dialog.getByLabel('Assessment name').fill('Offline CAT 3')
  await dialog.getByLabel('Assessment date').fill('2025-09-10')
  await dialog.getByRole('button', { name: 'Continue' }).click()
  await dialog.getByRole('button', { name: /Grade 4 · Stream 4W/ }).click()
  await dialog.getByRole('button', { name: 'Create' }).click()
  await expect(dialog.getByText('Assessment created')).toBeVisible()
  await dialog.getByRole('button', { name: 'Close' }).click()

  await page.getByRole('button', { name: 'Open', exact: true }).click()
  await page.getByRole('button', { name: 'Edit' }).click()
  await page.getByLabel('Mark out of 20').fill('15')
  await page.getByRole('button', { name: 'Finalize' }).click()
  await page.getByRole('dialog', { name: 'Finalize assessment?' }).getByRole('button', { name: 'Finalize' }).click()
  await expect(page.getByText('Finalized', { exact: true })).toBeVisible()

  /* Everything survives a reload with no network, and the display says it is waiting. */
  await page.reload()
  await expect(page.getByTestId('sync-line')).toHaveAttribute('data-sync-category', 'waiting')
  expect(await outboxCount(page)).toBe(4)
}

async function comeBackOnline(page: Page, context: BrowserContext) {
  const server = await fakeSyncServer(page)
  await page.unroute('**/api/health')
  await page.route('**/api/health', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) }),
  )
  await context.setOffline(false)
  await page.evaluate(() => window.dispatchEvent(new Event('online')))
  return server
}

test.describe('reconciling offline edits with no clicks', () => {
  test.setTimeout(90_000)

  test('sends the creates and marks first and the finalize after the create is acknowledged, and settles to synced', async ({ page, context }) => {
    await editOffline(page, context)
    const server = await comeBackOnline(page, context)

    await expect(page.getByTestId('sync-line')).toHaveAttribute('data-sync-category', 'synced', { timeout: 30_000 })
    expect(await outboxCount(page)).toBe(0)

    const rounds = server.rounds()
    expect(rounds.every((entries) => entries.length <= 100)).toBe(true)
    expect(rounds).toHaveLength(2)
    expect(rounds[0].map((entry) => entry.table)).toEqual(['marks', 'assessments', 'marks'])
    expect(rounds[0].every((entry) => entry.baseVersion === 0 || entry.table === 'marks')).toBe(true)
    expect(rounds[0].some((entry) => entry.fields.status === 'finalized')).toBe(false)
    expect(rounds[1]).toHaveLength(1)
    expect(rounds[1][0]).toMatchObject({ table: 'assessments', fields: { status: 'finalized' }, baseVersion: 1 })
    expect(rounds[1][0].recordId).toBe(rounds[0][1].recordId)
  })

  test('resends a request whose response was lost with an identical body', async ({ page, context }) => {
    await editOffline(page, context)
    const server = await comeBackOnline(page, context)
    server.control.dropNextResponse = true

    await expect(page.getByTestId('sync-line')).toHaveAttribute('data-sync-category', 'synced', { timeout: 45_000 })

    expect(server.bodies.length).toBeGreaterThanOrEqual(3)
    expect(server.bodies[1]).toBe(server.bodies[0])
    expect(await outboxCount(page)).toBe(0)
  })
})

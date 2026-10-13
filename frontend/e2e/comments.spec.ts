import { expect, test, type Page } from '@playwright/test'
import { E2E_SYNC_CHANGES, fakeSyncServer, signIn, type E2ESyncChange } from './support'

/* Build plan 5.3 for the comment review screen (#158): offline is its normal state, so a
   teacher edits and accepts a report-card comment with no connection, the screen shows both
   at once and keeps them across a reload, and once the device is back online the two commands
   go out in order, the second against the version the first was answered at. The API is faked
   (support.ts). */

const CLOSED_TERM: E2ESyncChange[] = [
  ...E2E_SYNC_CHANGES,
  { table: 'term_closures', recordId: 'closure-1', version: 1, fields: { classId: 'class-1', year: 2025, term: 2, closedBy: 'e2e-teacher', closedAt: '2025-08-01T08:00:00Z' } },
  { table: 'comments', recordId: 'comment-1', version: 2, fields: {
    studentId: 'student-1', year: 2025, term: 2, termClosureId: 'closure-1', authorId: null, body: 'Amina reads with growing confidence.', state: 'draft',
  } },
]

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

test('a comment is edited and accepted offline, survives a reload, and syncs in order once online', async ({ page, context }) => {
  test.setTimeout(90_000)
  await signIn(page, CLOSED_TERM)
  await page.route('**/api/health', (route) => route.abort())
  await context.setOffline(true)

  await page.goto('/classes/class-1/comments/2025/2')
  const card = page.getByRole('article').filter({ hasText: 'Amina Osei' })
  await expect(card.getByText('Draft', { exact: true })).toBeVisible()

  await card.getByRole('button', { name: 'Edit' }).click()
  await card.getByLabel('Comment for Amina Osei').fill('Amina reads widely and writes with care.')
  await card.getByRole('button', { name: 'Save as draft' }).click()
  await expect(card.getByText('Amina reads widely and writes with care.')).toBeVisible()
  await expect(card.getByText('Waiting to sync')).toBeVisible()

  await card.getByRole('button', { name: 'Accept' }).click()
  await page.getByRole('dialog', { name: 'Accept and issue the card?' }).getByRole('button', { name: 'Accept' }).click()
  await expect(card.getByText('Accepted', { exact: true })).toBeVisible()
  await expect(card.getByRole('button', { name: 'Edit' })).toHaveCount(0)

  await page.reload()
  await expect(card.getByText('Accepted', { exact: true })).toBeVisible()
  await expect(card.getByText('Amina reads widely and writes with care.')).toBeVisible()
  expect(await outboxCount(page)).toBe(2)

  const server = await fakeSyncServer(page, { versions: { 'comment-1': 2 } })
  await page.unroute('**/api/health')
  await page.route('**/api/health', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) }),
  )
  await context.setOffline(false)
  await page.evaluate(() => window.dispatchEvent(new Event('online')))

  await expect.poll(() => server.rounds().flat().length, { timeout: 45_000 }).toBe(2)
  const [edit, accept] = server.rounds().flat()
  expect(edit).toMatchObject({ table: 'comments', recordId: 'comment-1', baseVersion: 2, fields: { edit: { byId: 'e2e-teacher', body: 'Amina reads widely and writes with care.' } } })
  expect(accept).toMatchObject({ table: 'comments', recordId: 'comment-1', baseVersion: 3, fields: { accept: { byId: 'e2e-teacher' } } })
  await expect(page.getByTestId('sync-line')).toHaveAttribute('data-sync-category', 'synced', { timeout: 30_000 })
  await expect(card.getByText('Accepted', { exact: true })).toBeVisible()
})

test('a teacher who is not the class teacher reads the remarks but is offered no way to change them (ADR 0014)', async ({ page }) => {
  const otherClassTeacher: E2ESyncChange[] = CLOSED_TERM.map((change) => change.recordId === 'class-1'
    ? { ...change, version: 2, fields: { ...change.fields, classTeacherId: 'teacher-2' } }
    : change)
  await signIn(page, [...otherClassTeacher, { table: 'users', recordId: 'teacher-2', version: 1, fields: { name: 'Peter Kariuki', email: 'p.kariuki@example.test' } }])

  await page.goto('/classes/class-1/comments/2025/2')
  const card = page.getByRole('article').filter({ hasText: 'Amina Osei' })
  await expect(card.getByText('Amina reads with growing confidence.')).toBeVisible()
  await expect(page.getByTestId('remarks-owner')).toHaveText('Remarks for Stream 4W are written by its class teacher, Peter Kariuki, or the Director of Studies.')
  await expect(card.getByRole('button')).toHaveCount(0)
})

import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import { markIdFor } from '../src/lib/markIdentity'
import { E2E_SYNC_CHANGES, E2E_USER, fakeSyncServer, signIn, type E2ESyncChange } from './support'

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


/* A conflict between two teachers over one mark, in the shape the server pulls it. */
async function conflictChanges(sides: { a: string; b: string }): Promise<E2ESyncChange[]> {
  const markId = await markIdFor('a1', 'student-1', 'criterion-1')
  const side = (editId: string, userId: string, who: string, scoreValue: number) => (
    { editId, userId, who, markKind: 'score', score: scoreValue, at: '2026-10-08T09:00:00Z' }
  )
  return [
    ...E2E_SYNC_CHANGES,
    { table: 'marks', recordId: markId, version: 2, fields: { assessmentId: 'a1', studentId: 'student-1', criterionId: 'criterion-1', markKind: 'score', score: 12, lastEditedBy: sides.a } },
    {
      table: 'conflicts', recordId: 'c1', version: 1,
      fields: {
        markId, baseVersion: 1,
        sideA: side('edit-a', sides.a, sides.a === E2E_USER.id ? E2E_USER.name : 'Ms. Akinyi', 12),
        sideB: side('edit-b', sides.b, sides.b === E2E_USER.id ? E2E_USER.name : 'Mr. Otieno', 15),
        proposals: [], referral: null, resolution: null, resolvedAt: null,
      },
    },
  ]
}

const outboxEntries = (page: Page) => page.evaluate(async () => {
  const database = await new Promise<IDBDatabase>((resolve, reject) => {
    const request = indexedDB.open('connected-user-e2e-teacher')
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
  const rows = await new Promise<Array<Record<string, unknown>>>((resolve, reject) => {
    const request = database.transaction('outbox').objectStore('outbox').getAll()
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
  database.close()
  return rows
})

test.describe('settling a conflict', () => {
  test.setTimeout(90_000)

  test('a party proposes and then refers offline, both survive a reload, and they go out in order once online', async ({ page, context }) => {
    await signIn(page, await conflictChanges({ a: 'teacher-akinyi', b: E2E_USER.id }))
    await page.route('**/api/health', (route) => route.abort())
    await context.setOffline(true)

    await page.goto('/sync')
    await page.getByRole('button', { name: /^Propose 12/ }).click()
    await page.getByLabel('Resolution note').fill('Re-marked against the rubric')
    await page.getByRole('button', { name: 'Send proposal' }).click()
    await expect(page.getByText('Waiting to sync')).toBeVisible()
    await page.getByRole('button', { name: 'Refer to a moderator' }).click()

    await expect.poll(async () => (await outboxEntries(page)).length).toBe(2)
    await page.reload()
    await expect(page.getByText('Waiting to sync')).toBeVisible()
    await expect(page.getByTestId('sync-line')).toHaveAttribute('data-sync-category', 'waiting')

    await page.unroute('**/api/health')
    const server = await fakeSyncServer(page, { versions: { c1: 1 } })
    await context.setOffline(false)
    await page.evaluate(() => window.dispatchEvent(new Event('online')))

    await expect(page.getByTestId('sync-line')).toHaveAttribute('data-sync-category', 'synced', { timeout: 30_000 })
    const rounds = server.rounds()
    expect(rounds.map((entries) => entries.map((entry) => [entry.table, Object.keys(entry.fields)[0], entry.baseVersion]))).toEqual([
      [['conflicts', 'proposal', 1]],
      [['conflicts', 'referral', 2]],
    ])
    expect(rounds[0][0].fields).toEqual({ proposal: { byId: E2E_USER.id, choice: { kind: 'side', editId: 'edit-a' }, note: 'Re-marked against the rubric' } })
    expect(await outboxEntries(page)).toHaveLength(0)
  })

  test('a moderator of the subject, who is not a party, can settle it, which needs the subject id and not its name', async ({ page, context }) => {
    const moderator = { ...E2E_USER, moderated_subject_ids: ['subject-1'] }
    await signIn(page, await conflictChanges({ a: 'teacher-akinyi', b: 'teacher-otieno' }), moderator)
    await page.route('**/api/health', (route) => route.abort())
    await context.setOffline(true)

    await page.goto('/sync')
    await page.getByRole('button', { name: /^Keep 12/ }).click()
    await expect(page.getByRole('button', { name: 'Confirm' })).toBeDisabled()
    await page.getByLabel('Resolution note').fill('Blind re-mark with both present')
    await page.getByRole('button', { name: 'Confirm' }).click()

    await expect(page.getByText('Settled items are under Historical.')).toBeVisible()
    const [command, ...rest] = await outboxEntries(page)
    expect(rest).toHaveLength(0)
    expect(command).toMatchObject({
      table: 'conflicts', recordId: 'c1', kind: 'command', baseVersion: 1, state: 'queued',
      fields: { resolution: { kind: 'moderated', byId: E2E_USER.id, choice: { kind: 'side', editId: 'edit-a' }, note: 'Blind re-mark with both present' } },
    })

    await page.reload()
    await page.getByRole('button', { name: 'Open navigation' }).click()
    await expect(page.getByText('Settled items are under Historical.')).toBeVisible()
  })

  test('a teacher settles their own two-device edits from the marking grid, which queues one command and no mark patch', async ({ page, context }) => {
    await signIn(page, await conflictChanges({ a: E2E_USER.id, b: E2E_USER.id }))
    await page.route('**/api/health', (route) => route.abort())
    await context.setOffline(true)

    await page.goto('/assessments/a1/grid')
    await page.getByRole('button', { name: /conflict/i }).click()
    const dialog = page.getByRole('dialog', { name: 'Mark conflict' })
    await dialog.getByRole('button', { name: /^Keep 15/ }).click()
    await dialog.getByRole('button', { name: 'Confirm' }).click()

    await expect(page.getByText('Conflict resolved; change queued for sync')).toBeVisible()
    const entries = await outboxEntries(page)
    expect(entries.map((entry) => [entry.table, entry.kind])).toEqual([['conflicts', 'command']])
    expect(entries[0].fields).toEqual({ resolution: { kind: 'self', byId: E2E_USER.id, choice: { kind: 'side', editId: 'edit-b' } } })

    /* The cell already holds the chosen mark, as a local value, and is no longer contested. */
    await expect(page.getByRole('button', { name: /conflict/i })).toHaveCount(0)
    await expect(page.getByRole('row', { name: /Amina Osei/ }).getByText('15', { exact: true }).first()).toBeVisible()
  })

  test('someone who moderates a different subject can only watch: the conflict is folded away and never counted', async ({ page, context }) => {
    const elsewhere = { ...E2E_USER, moderated_subject_ids: ['subject-other'] }
    await signIn(page, await conflictChanges({ a: 'teacher-akinyi', b: 'teacher-otieno' }), elsewhere)
    await context.setOffline(true)

    await page.goto('/sync')

    await expect(page.getByText('All conflicts resolved').first()).toBeVisible()
    await expect(page.getByText('Other conflicts (1)')).toBeVisible()
    await expect(page.getByRole('button', { name: /^Keep / })).toHaveCount(0)
    await page.getByText('Other conflicts (1)').click()
    await expect(page.getByRole('button', { name: /^Open in marking grid: Amina Osei, Comprehension/ })).toBeVisible()
    await expect(page.getByRole('button', { name: /^Keep / })).toHaveCount(0)
    expect(await outboxEntries(page)).toHaveLength(0)
  })
})

/* One definition of "needs you", used by the banner, the dashboard, and the top bar. */
test.describe('counting what needs a person', () => {
  const needsReview = (page: Page) => page.getByText('Needs Review', { exact: true }).locator('xpath=..')

  test('a party sees the conflict in the banner, the dashboard, and the top bar', async ({ page }) => {
    await signIn(page, await conflictChanges({ a: 'teacher-akinyi', b: E2E_USER.id }))

    await expect(page.getByText('Needs attention')).toBeVisible()
    await expect(needsReview(page)).toContainText('1')
    await expect(page.getByTestId('sync-line')).toHaveAttribute('data-sync-category', 'attention')
  })

  test('someone who can only watch sees none of it', async ({ page }) => {
    const elsewhere = { ...E2E_USER, moderated_subject_ids: ['subject-other'] }
    await signIn(page, await conflictChanges({ a: 'teacher-akinyi', b: 'teacher-otieno' }), elsewhere)

    await expect(page.getByText('Needs attention')).toHaveCount(0)
    await expect(needsReview(page)).toContainText('0')
    await expect(page.getByTestId('sync-line')).not.toHaveAttribute('data-sync-category', 'attention')
  })

  test('a moderator of the subject counts a cross-teacher conflict, which a party would not have to act on', async ({ page }) => {
    const moderator = { ...E2E_USER, moderated_subject_ids: ['subject-1'] }
    await signIn(page, await conflictChanges({ a: 'teacher-akinyi', b: 'teacher-otieno' }), moderator)

    await expect(page.getByText('Needs attention')).toBeVisible()
    await expect(needsReview(page)).toContainText('1')
  })
})

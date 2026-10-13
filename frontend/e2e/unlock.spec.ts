import { expect, test, type Page } from '@playwright/test'
import { E2E_SYNC_CHANGES, E2E_USER, signIn, type E2ESyncChange } from './support'

/* ADR 0014, decision 3: unlocking happens in the app, for a Director of Studies or the HOD of
   the assessment's subject, online only. The API is faked; the server's own rules are its tests. */

const FINALIZED: E2ESyncChange[] = [
  ...E2E_SYNC_CHANGES.filter((change) => change.recordId !== 'a1'),
  { table: 'assessments', recordId: 'a1', version: 2, fields: {
    classId: 'class-1', subjectId: 'subject-1', name: 'CAT 1', term: 'Term 2', year: 2025, date: '2025-05-12', status: 'finalized',
  } },
]

async function reachableApi(page: Page) {
  await page.route('**/api/health', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) }),
  )
}

test('the HOD of the subject unlocks a finalized assessment, and is told the note the server requires', async ({ page }) => {
  await reachableApi(page)
  await signIn(page, FINALIZED, { ...E2E_USER, moderated_subject_ids: ['subject-1'] })
  const bodies: unknown[] = []
  await page.route('**/api/assessments/a1/unlock', async (route) => {
    const body = route.request().postDataJSON()
    bodies.push(body)
    await route.fulfill(body.note
      ? { status: 200, contentType: 'application/json', body: JSON.stringify({ data: { id: 'a1', status: 'scheduled', version: 3 } }) }
      : { status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'A resolution note is required when reports exist.' }) })
  })

  await page.goto('/assessments/a1/grid')
  await page.getByRole('button', { name: 'Unlock' }).click()
  const dialog = page.getByRole('dialog', { name: 'Unlock assessment?' })
  await dialog.getByRole('button', { name: 'Unlock' }).click()
  await expect(dialog.getByRole('alert')).toHaveText('A resolution note is required when reports exist.')

  /* Cancelling and reopening starts clean, not with the last attempt's refusal. */
  await dialog.getByLabel('Reason for unlocking').fill('half-typed')
  await dialog.getByRole('button', { name: 'Cancel' }).click()
  await expect(dialog).toBeHidden()
  await page.getByRole('button', { name: 'Unlock' }).click()
  await expect(dialog.getByRole('alert')).toHaveCount(0)
  await expect(dialog.getByLabel('Reason for unlocking')).toHaveValue('')

  await dialog.getByLabel('Reason for unlocking').fill('Correct the recorded score.')
  await dialog.getByRole('button', { name: 'Unlock' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Unlocked.' })).toBeVisible()
  expect(bodies).toEqual([{}, { note: 'Correct the recorded score.' }])
})

test('a teacher who neither directs studies nor heads the subject is offered no unlock', async ({ page }) => {
  await reachableApi(page)
  await signIn(page, FINALIZED)

  await page.goto('/assessments/a1/grid')
  await expect(page.getByText('Finalized', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Unlock' })).toHaveCount(0)
})

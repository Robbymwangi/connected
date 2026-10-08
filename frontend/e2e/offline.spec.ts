import { expect, test, type Page } from '@playwright/test'
import { E2E_USER, signIn, type E2ESyncChange } from './support'

/* The offline guarantee across the cases that broke the hand-written worker and
   drove the move to Workbox (ADR 0004): first visit, then a reload and a deep link
   with no network. Each test uses its own context so one test's worker and caches
   do not leak into the next.

   The app now gates on sign-in (build plan 1.7, #45); support.ts's signIn() gets
   past that once through faked routes, then removes them. The session that lands
   in IndexedDB (lib/sessionStorage.ts) is what then survives the reload and the
   fresh tab below with no network at all and no fake answering underneath it:
   that survival is this suite's proof that IndexedDB over sessionStorage was the
   right call, not just an assertion about the dashboard heading. */

async function loadOnceOnline(page: Page) {
  await signIn(page)
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

test('Classes and assessment selectors read school reference data from the local store', async ({ page }) => {
  const changes: E2ESyncChange[] = [
    { table: 'users', recordId: E2E_USER.id, version: 1, fields: { name: 'Jane Teacher', email: E2E_USER.email } },
    { table: 'classes', recordId: 'class-1', version: 1, fields: { grade: 'Grade 4', stream: '4W', classTeacherId: E2E_USER.id } },
    { table: 'subjects', recordId: 'subject-1', version: 1, fields: { name: 'English' } },
    { table: 'criteria', recordId: 'criterion-1', version: 1, fields: { subjectId: 'subject-1', name: 'Comprehension', maxScore: 20 } },
    { table: 'class_subjects', recordId: 'class-subject-1', version: 1, fields: { classId: 'class-1', subjectId: 'subject-1' } },
    { table: 'teacher_assignments', recordId: 'assignment-1', version: 1, fields: { userId: E2E_USER.id, classId: 'class-1', subjectId: 'subject-1' } },
    { table: 'students', recordId: 'student-1', version: 1, fields: { name: 'Amina Osei', gender: 'F', dob: '2016-01-19' } },
    { table: 'enrolments', recordId: 'enrolment-1', version: 1, fields: { studentId: 'student-1', classId: 'class-1', year: 2025 } },
  ]

  await signIn(page, changes)
  await page.getByRole('button', { name: 'Open navigation' }).click()
  await page.getByRole('button', { name: 'Classes', exact: true }).click()
  await expect(page.getByText('Grade 4', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: /Stream 4W/ }).click()
  await expect(page.getByRole('heading', { name: 'Stream 4W' })).toBeVisible()
  await expect(page.getByText('Amina Osei')).toBeVisible()
  await expect(page.getByText(/class teacher Jane Teacher/)).toBeVisible()

  await page.getByRole('button', { name: 'Open navigation' }).click()
  await page.getByRole('button', { name: 'Assessments' }).click()
  await page.getByRole('button', { name: 'New' }).click()
  const dialog = page.getByRole('dialog', { name: 'New Assessment' })
  await page.getByRole('button', { name: /English/ }).click()
  await expect(page.getByText('Comprehension')).toBeVisible()
  await dialog.getByRole('button', { name: 'Continue' }).click()
  await page.getByLabel('Assessment name').fill('CAT 1')
  await page.getByLabel('Assessment date').fill('2025-05-12')
  await dialog.getByRole('button', { name: 'Continue' }).click()
  await expect(page.getByRole('button', { name: /Grade 4 · Stream 4W/ })).toBeVisible()
})

import { expect, test, type Page } from '@playwright/test'
import { E2E_SYNC_CHANGES, E2E_USER, signIn, type E2ESyncChange } from './support'
import { markIdFor } from '../src/lib/markIdentity'

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

test('a mark edit is saved to Dexie and survives an offline reload', async ({ page, context }) => {
  /* The API is unreachable here, so the sync runner stays idle and the entry is still
     queued when it is read back; with a reachable API it would be sent at once. */
  await page.route('**/api/health', (route) => route.abort())
  await signIn(page)
  await page.goto('/assessments/a1/grid')
  await page.getByRole('button', { name: 'Edit' }).click()
  await page.getByLabel('Mark out of 20').fill('17')

  await page.waitForFunction(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected-user-e2e-teacher')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const rows = await new Promise<Array<Record<string, unknown>>>((resolve, reject) => {
      const request = database.transaction('marks').objectStore('marks').getAll()
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    database.close()
    return rows.some((row) => row.assessmentId === 'a1' && row.studentId === 'student-1' && row.criterionId === 'criterion-1' && row.score === 17)
  })

  await context.setOffline(true)
  await page.reload()
  await expect(page.getByRole('heading', { level: 1 })).toContainText('English')
  const studentRow = page.getByRole('row', { name: /Amina Osei/ })
  await expect(studentRow.getByText('17', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('local', { exact: true })).toBeVisible()

  const { saved, outbox } = await page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected-user-e2e-teacher')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const all = (store: string) => new Promise<Array<Record<string, unknown>>>((resolve, reject) => {
      const request = database.transaction(store).objectStore(store).getAll()
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const rows = await all('marks')
    const entries = await all('outbox')
    database.close()
    return {
      saved: rows.find((row) => row.assessmentId === 'a1' && row.studentId === 'student-1' && row.criterionId === 'criterion-1'),
      outbox: entries,
    }
  })

  expect(saved).toMatchObject({ version: 0, markKind: 'score', score: 17, sync: 'pending' })
  expect(saved).not.toHaveProperty('pendingFields')
  expect(outbox).toHaveLength(1)
  expect(outbox[0]).toMatchObject({
    table: 'marks',
    recordId: saved?.id,
    kind: 'patch',
    baseVersion: 0,
    state: 'queued',
    fields: { assessmentId: 'a1', studentId: 'student-1', criterionId: 'criterion-1', markKind: 'score', score: 17 },
  })
})

test('Classes and assessment selectors read school reference data from the local store', async ({ page }) => {
  const changes: E2ESyncChange[] = [
    { table: 'users', recordId: E2E_USER.id, version: 1, fields: { name: 'Jane Teacher', email: E2E_USER.email } },
    { table: 'classes', recordId: 'class-1', version: 1, fields: { grade: 'Grade 4', stream: '4W', classTeacherId: E2E_USER.id } },
    { table: 'subjects', recordId: 'subject-1', version: 1, fields: { name: 'English' } },
    { table: 'subjects', recordId: 'subject-2', version: 1, fields: { name: 'Kiswahili' } },
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
  await page.getByRole('button', { name: 'Assessments', exact: true }).click()
  await page.getByRole('button', { name: 'Subject' }).click()
  await expect(page.getByRole('option', { name: 'Kiswahili' })).toBeVisible()
  await page.getByRole('button', { name: 'Subject' }).click()
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

test('student profile reads finalized results from the local store', async ({ page }) => {
  const changes: E2ESyncChange[] = [
    ...E2E_SYNC_CHANGES,
    {
      table: 'assessments', recordId: 'assessment-final', version: 1,
      fields: {
        classId: 'class-1', subjectId: 'subject-1', name: 'Final exam', term: 'Term 1',
        year: 2025, date: '2025-06-20', status: 'finalized',
      },
    },
    {
      table: 'results', recordId: 'result-1', version: 1,
      fields: { assessmentId: 'assessment-final', studentId: 'student-1', total: 14, max: 18, level: 'ME' },
    },
  ]

  await signIn(page, changes)
  await page.getByRole('button', { name: 'Open navigation' }).click()
  await page.getByRole('button', { name: 'Classes', exact: true }).click()
  await page.getByRole('button', { name: /Stream 4W/ }).click()
  await page.getByRole('button', { name: /Amina Osei/ }).click()

  await expect(page.getByText('English: Final exam')).toBeVisible()
  await expect(page.getByText('14/18')).toBeVisible()
  await expect(page.getByText('ME', { exact: true })).toBeVisible()
})

test('notification popup reads only the signed-in user rows from the local store', async ({ page }) => {
  const changes: E2ESyncChange[] = [
    ...E2E_SYNC_CHANGES,
    {
      table: 'notifications', recordId: 'notification-own', version: 1,
      fields: { userId: E2E_USER.id, kind: 'edit-blocked', tone: 'danger', title: 'Edit not applied', body: 'CAT 1 was finalized.', unread: true },
    },
    {
      table: 'notifications', recordId: 'notification-other', version: 1,
      fields: { userId: 'another-user', kind: 'sync-conflict', tone: 'warning', title: 'Private notice', body: 'Do not show.', unread: true },
    },
  ]

  await signIn(page, changes)
  await page.getByRole('button', { name: 'Notifications' }).click()
  await expect(page.getByText('Edit not applied')).toBeVisible()
  await expect(page.getByText('CAT 1 was finalized.')).toBeVisible()
  await expect(page.getByText('Private notice')).toHaveCount(0)
  await expect(page.getByText('Do not show.')).toHaveCount(0)
  /* The notification's own row, not the page: the top bar and the dashboard now say
     "Synced just now" legitimately. The row holds the title and the body, so it is the
     right scope, and it must not invent a time of its own. */
  const row = page.getByText('Edit not applied').locator('xpath=ancestor::div[2]')
  await expect(row).toContainText('CAT 1 was finalized.')
  await expect(row).not.toContainText('just now')
  await page.getByRole('button', { name: 'Mark all read' }).click()
  await expect(page.getByRole('button', { name: 'Mark all read' })).toHaveCount(0)
})

test('a newly created assessment survives an offline reload in the local store', async ({ page, context }) => {
  await signIn(page)
  await page.getByRole('button', { name: 'Open navigation' }).click()
  await page.getByRole('button', { name: 'Assessments', exact: true }).click()
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

  await context.setOffline(true)
  await page.reload()

  const saved = await page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected-user-e2e-teacher')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const assessments = await new Promise<unknown[]>((resolve, reject) => {
      const request = database.transaction('assessments').objectStore('assessments').getAll()
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    database.close()
    return assessments.find((assessment) => typeof assessment === 'object' && assessment !== null && 'name' in assessment && assessment.name === 'Offline CAT 3')
  })

  expect(saved).toMatchObject({
    version: 0,
    classId: 'class-1',
    subjectId: 'subject-1',
    name: 'Offline CAT 3',
    status: 'scheduled',
  })
})

test('conflict correction uses the synced criterion maximum', async ({ page }) => {
  const assessmentId = 'assessment-kiswahili'
  const studentId = 'student-1'
  const criterionId = 'criterion-kiswahili'
  const markId = await markIdFor(assessmentId, studentId, criterionId)
  const changes: E2ESyncChange[] = [
    ...E2E_SYNC_CHANGES,
    { table: 'subjects', recordId: 'subject-kiswahili', version: 1, fields: { name: 'Kiswahili' } },
    { table: 'class_subjects', recordId: 'link-kiswahili', version: 1, fields: { classId: 'class-1', subjectId: 'subject-kiswahili' } },
    { table: 'criteria', recordId: criterionId, version: 1, fields: { subjectId: 'subject-kiswahili', name: 'Reading', maxScore: 18 } },
    { table: 'teacher_assignments', recordId: 'assignment-kiswahili', version: 1, fields: { userId: E2E_USER.id, classId: 'class-1', subjectId: 'subject-kiswahili' } },
    {
      table: 'assessments', recordId: assessmentId, version: 1,
      fields: { classId: 'class-1', subjectId: 'subject-kiswahili', name: 'Kiswahili CAT', term: 'Term 1', year: 2025, date: '2025-05-20', status: 'in-progress' },
    },
    {
      table: 'marks', recordId: markId, version: 2,
      fields: { assessmentId, studentId, criterionId, markKind: 'score', score: 12, lastEditedBy: E2E_USER.id },
    },
    {
      table: 'conflicts', recordId: 'conflict-kiswahili', version: 1,
      fields: {
        markId, baseVersion: 1,
        sideA: { editId: 'edit-k1', userId: E2E_USER.id, who: E2E_USER.name, markKind: 'score', score: 12, at: '2026-10-08T09:00:00Z' },
        sideB: { editId: 'edit-k2', userId: E2E_USER.id, who: E2E_USER.name, markKind: 'score', score: 10, at: '2026-10-08T09:01:00Z' },
        proposals: [], referral: null, resolution: null, resolvedAt: null,
      },
    },
  ]

  await signIn(page, changes)
  await page.goto('/sync')
  await page.getByRole('button', { name: 'Enter corrected' }).click()
  await expect(page.getByLabel('Corrected mark')).toHaveAttribute('placeholder', 'Corrected mark, 0 to 18, or A for absent')
  await page.getByLabel('Corrected mark').fill('17')
  await expect(page.getByRole('button', { name: 'Confirm' })).toBeEnabled()
})

test('finalizing an assessment persists its local command state offline', async ({ page, context }) => {
  const changes: E2ESyncChange[] = [
    ...E2E_SYNC_CHANGES,
    {
      table: 'marks', recordId: 'mark-1', version: 1,
      fields: {
        assessmentId: 'a1', studentId: 'student-1', criterionId: 'criterion-1',
        markKind: 'score', score: 16, lastEditedBy: E2E_USER.id,
      },
    },
  ]

  await signIn(page, changes)
  await page.goto('/assessments/a1/grid')
  await page.getByRole('button', { name: 'Finalize' }).click()
  const dialog = page.getByRole('dialog', { name: 'Finalize assessment?' })
  await dialog.getByRole('button', { name: 'Finalize' }).click()
  await expect(page.getByText('Finalized', { exact: true })).toBeVisible()

  await context.setOffline(true)
  await page.reload()
  await expect(page.getByText('Finalized', { exact: true })).toBeVisible()

  const saved = await page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected-user-e2e-teacher')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const assessment = await new Promise<unknown>((resolve, reject) => {
      const request = database.transaction('assessments').objectStore('assessments').get('a1')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    database.close()
    return assessment
  })

  expect(saved).toMatchObject({
    status: 'finalized',
    finalizedBy: E2E_USER.id,
    sync: 'pending',
  })
})

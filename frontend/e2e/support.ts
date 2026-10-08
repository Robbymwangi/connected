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

export const E2E_SYNC_CHANGES: E2ESyncChange[] = [
  { table: 'users', recordId: E2E_USER.id, version: 1, fields: { name: 'Jane Teacher', email: E2E_USER.email } },
  { table: 'classes', recordId: 'class-1', version: 1, fields: { grade: 'Grade 4', stream: '4W', classTeacherId: E2E_USER.id } },
  { table: 'subjects', recordId: 'subject-1', version: 1, fields: { name: 'English' } },
  { table: 'criteria', recordId: 'criterion-1', version: 1, fields: { subjectId: 'subject-1', name: 'Comprehension', maxScore: 20 } },
  { table: 'class_subjects', recordId: 'class-subject-1', version: 1, fields: { classId: 'class-1', subjectId: 'subject-1' } },
  { table: 'teacher_assignments', recordId: 'assignment-1', version: 1, fields: { userId: E2E_USER.id, classId: 'class-1', subjectId: 'subject-1' } },
  { table: 'students', recordId: 'student-1', version: 1, fields: { name: 'Amina Osei', gender: 'F', dob: '2016-01-19' } },
  { table: 'enrolments', recordId: 'enrolment-1', version: 1, fields: { studentId: 'student-1', classId: 'class-1', year: 2025 } },
  { table: 'assessments', recordId: 'a1', version: 1, fields: {
    classId: 'class-1', subjectId: 'subject-1', name: 'CAT 1', term: 'Term 2', year: 2025, date: '2025-05-12', status: 'scheduled',
  } },
]

/* Fakes login, /me, and the initial sync pull just long enough to sign in and
  populate IndexedDB, then removes the routes. Later reloads and offline
  checks depend on local data or the real network, never on these fakes. */
export async function signIn(page: Page, syncChanges: E2ESyncChange[] = E2E_SYNC_CHANGES) {
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

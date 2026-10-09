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
export async function signIn(page: Page, syncChanges: E2ESyncChange[] = E2E_SYNC_CHANGES, user: typeof E2E_USER = E2E_USER) {
  await page.route('**/api/login', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ token: 'e2e-token' }) }),
  )
  await page.route('**/api/me', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(user) }),
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

  /* The response arriving is not the pull being applied: the changes are written to
     IndexedDB a moment later, and a spec that navigates straight away can cut that
     write off and find an empty store. The cursor is committed in the same transaction
     as the changes, so its presence means they are all there. */
  await page.waitForFunction(async (userId) => {
    /* Opening a database that does not exist yet would create it, empty, and race the
       app's own versioned open; so look first, and only then open. */
    const name = `connected-user-${userId}`
    if (!(await indexedDB.databases()).some((known) => known.name === name)) return false
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open(name)
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    if (!database.objectStoreNames.contains('metadata')) {
      database.close()
      return false
    }
    const stored = await new Promise<unknown>((resolve, reject) => {
      const request = database.transaction('metadata').objectStore('metadata').get(`syncCursor:${userId}`)
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    database.close()
    return stored !== undefined
  }, E2E_USER.id)

  await page.unroute('**/api/login')
  await page.unroute('**/api/me')
  await page.unroute('**/api/sync**')
}

export type RecordedEntry = {
  id: string
  table: string
  recordId: string
  baseVersion: number
  fields: Record<string, unknown>
}

/* Stands in for the API once a spec brings the device back online: a reachable
   /api/health, an empty pull, and a POST /api/sync that accepts every entry and numbers
   each record's versions from 1. It records the raw body of every POST, so a spec can
   assert on the order of the rounds and that a resend is byte-identical. Install it
   after going offline-to-online is wanted, not before: while offline, edits must
   queue, and a reachable health route would send them. */
export async function fakeSyncServer(page: Page, options: { versions?: Record<string, number> } = {}) {
  const bodies: string[] = []
  const versions = new Map<string, number>(Object.entries(options.versions ?? {}))
  /* What a pull is told after a conflict command is accepted: the conflict at its new
     version, so the device's copy catches up as it would from the real change log. */
  const served: E2ESyncChange[] = []
  const control = { dropNextResponse: false }

  await page.route('**/api/health', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) }),
  )
  await page.route('**/api/sync**', async (route) => {
    const request = route.request()
    if (request.method() !== 'POST') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ changes: served, cursor: 2, more: false }) })
      return
    }

    const raw = request.postData() ?? ''
    bodies.push(raw)
    if (control.dropNextResponse) {
      control.dropNextResponse = false
      await route.abort('connectionreset')
      return
    }

    const entries = (JSON.parse(raw) as { entries: RecordedEntry[] }).entries
    const results = entries.map((entry) => {
      const version = (versions.get(entry.recordId) ?? 0) + 1
      versions.set(entry.recordId, version)
      if (entry.table === 'conflicts') {
        const kept = served.filter((change) => change.recordId !== entry.recordId)
        served.length = 0
        served.push(...kept, { table: 'conflicts', recordId: entry.recordId, version, fields: {} })
      }
      return { id: entry.id, status: 'accepted', version }
    })
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ results }) })
  })

  return {
    bodies,
    control,
    rounds: () => bodies.map((raw) => (JSON.parse(raw) as { entries: RecordedEntry[] }).entries),
  }
}

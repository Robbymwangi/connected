import { expect, test, type Page } from '@playwright/test'
import { E2E_USER, signIn } from './support'

/* Build plan 1.7 (#45): sign in, an offline reload still shows the same
   user, and sign out. No real API runs during these specs; /api/login and
   /api/me are faked per test. */

async function answerLogin(page: Page, outcome: 'ok' | 'wrong-credentials' | 'rate-limited') {
  await page.unroute('**/api/login')
  await page.route('**/api/login', (route) => {
    if (outcome === 'ok') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ token: 'tok' }) })
    }
    if (outcome === 'wrong-credentials') {
      return route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'These credentials do not match our records.' }),
      })
    }
    return route.fulfill({
      status: 429,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Too Many Attempts.' }),
    })
  })
}

test('a wrong password shows the same message an unknown email would', async ({ page }) => {
  await answerLogin(page, 'wrong-credentials')
  await page.goto('/')
  await page.getByLabel('Email').fill('nobody@example.test')
  await page.getByLabel('Password').fill('wrong')
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page.getByRole('alert')).toHaveText('Incorrect email or password.')
  /* Still on the sign-in form; a rejected attempt does not half-authenticate. */
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
})

test('a rate-limited attempt is distinguished from a wrong password', async ({ page }) => {
  await answerLogin(page, 'rate-limited')
  await page.goto('/')
  await page.getByLabel('Email').fill(E2E_USER.email)
  await page.getByLabel('Password').fill('whatever')
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page.getByRole('alert')).toContainText(/too many attempts/i)
})

test('signing in shows the account from /me, and reloading offline shows the same one', async ({ page, context }) => {
  await signIn(page)
  await expect(page.getByText(E2E_USER.name).first()).toBeVisible()

  await context.setOffline(true)
  await page.reload()

  await expect(page.getByRole('heading', { level: 1, name: /morning|afternoon|evening/i })).toBeVisible()
  await expect(page.getByText(E2E_USER.name).first()).toBeVisible()
})

test('a legacy cached session migrates into Dexie and starts sync before an offline reload', async ({ page, context }) => {
  const legacySession = {
    token: 'legacy-token',
    user: {
      id: E2E_USER.id,
      firstName: 'Jane',
      fullName: E2E_USER.name,
      initials: 'JT',
      role: 'Teacher',
      email: E2E_USER.email,
      moderatedSubjectIds: [],
    },
  }

  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
  await page.waitForFunction(() => navigator.serviceWorker.controller !== null)
  await page.evaluate(async (session) => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected-session', 1)
      request.onupgradeneeded = () => request.result.createObjectStore('session')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    await new Promise<void>((resolve, reject) => {
      const transaction = database.transaction('session', 'readwrite')
      transaction.objectStore('session').put(session, 'current')
      transaction.oncomplete = () => resolve()
      transaction.onerror = () => reject(transaction.error)
    })
    database.close()
  }, legacySession)

  await page.route('**/api/me', (route) => route.fulfill({ status: 500, body: 'offline' }))
  await page.route('**/api/sync**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        changes: [{ table: 'students', recordId: 'student-1', version: 1, fields: { name: 'Amina' } }],
        cursor: 7,
        more: false,
      }),
    }),
  )

  const syncResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/sync')
  await page.reload()
  await expect(page.getByRole('heading', { level: 1, name: /morning|afternoon|evening/i })).toBeVisible()
  await syncResponse

  const migrated = await page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const session = await new Promise<unknown>((resolve, reject) => {
      const request = database.transaction('sessions').objectStore('sessions').get('current')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    database.close()

    const accountDatabase = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected-user-e2e-teacher')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const student = await new Promise<unknown>((resolve, reject) => {
      const request = accountDatabase.transaction('students').objectStore('students').get('student-1')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    accountDatabase.close()

    const legacyDatabase = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected-session')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const oldSession = await new Promise<unknown>((resolve, reject) => {
      const request = legacyDatabase.transaction('session').objectStore('session').get('current')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    legacyDatabase.close()

    return { session, student, oldSession }
  })

  expect(migrated.session).toEqual({ key: 'current', session: legacySession })
  expect(migrated.student).toMatchObject({ id: 'student-1', version: 1, name: 'Amina' })
  expect(migrated.oldSession).toBeUndefined()

  await context.setOffline(true)
  await page.reload()
  await expect(page.getByRole('heading', { level: 1, name: /morning|afternoon|evening/i })).toBeVisible()
})

test('a second, fresh tab in the same context also sees the signed-in account offline', async ({ page, context }) => {
  await signIn(page)
  await context.setOffline(true)

  const tab = await context.newPage()
  await tab.goto('/')
  await expect(tab.getByRole('heading', { level: 1, name: /morning|afternoon|evening/i })).toBeVisible()
})

test('signing out returns to the sign-in form, and a reload does not restore the old session', async ({ page }) => {
  await signIn(page)

  /* The sidebar (and its Log Out button) starts collapsed off-screen; the
     hamburger in the top bar pins it open. */
  await page.getByRole('button', { name: 'Open navigation' }).click()
  await page.getByRole('button', { name: 'Log Out' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()

  const storedSession = await page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('connected')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const session = await new Promise<unknown>((resolve, reject) => {
      const request = database.transaction('sessions').objectStore('sessions').get('current')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    database.close()
    return session
  })
  expect(storedSession).toBeUndefined()

  await page.reload()
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
})

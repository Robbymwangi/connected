import { expect, test, type Page } from '@playwright/test'
import { signIn } from './support'

/* The health probe (#44, lib/health.ts): the pill follows whether the API
   answers, not only whether the browser reports a link. Every test keeps the
   browser online throughout (navigator.onLine stays true) and changes only what
   /api/health returns, which is the captive-portal case navigator.onLine alone
   cannot see. The route is faked, so no API needs to be running.

   The pill lives in the top bar, inside the signed-in app shell (build plan
   1.7, #45), so every test signs in first via support.ts before it can be
   seen at all. The health route is answered from the start of each test, so
   it is already in place before sign-in's own navigation triggers the
   first probe. */

const healthy = { status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) }

async function answerHealth(page: Page, answer: 'ok' | 'down' | 'portal') {
  await page.unroute('**/api/health')
  await page.route('**/api/health', (route) => {
    if (answer === 'ok') return route.fulfill(healthy)
    if (answer === 'portal') return route.fulfill({ status: 200, contentType: 'text/html', body: '<html>Sign in</html>' })
    return route.abort('connectionrefused')
  })
}

/* The browser saying it is back is the cue to probe again at once; dispatching the
   event is the same as a real reconnect, without waiting out the poll interval. */
const reconnect = (page: Page) => page.evaluate(() => window.dispatchEvent(new Event('online')))

const pill = (page: Page, label: 'Online' | 'Offline') => page.getByText(label, { exact: true })

test('the pill flips offline when the probe fails behind a browser that says online, and back', async ({ page }) => {
  await answerHealth(page, 'ok')
  await signIn(page)
  await expect(pill(page, 'Online')).toBeVisible()

  await answerHealth(page, 'down')
  await reconnect(page)
  await expect(pill(page, 'Offline')).toBeVisible()
  expect(await page.evaluate(() => navigator.onLine)).toBe(true)

  await answerHealth(page, 'ok')
  await reconnect(page)
  await expect(pill(page, 'Online')).toBeVisible()
})

test('coming back online with the API still down never reads online, not even for a moment', async ({ page, context }) => {
  await answerHealth(page, 'ok')
  await signIn(page)
  await expect(pill(page, 'Online')).toBeVisible()

  /* Record every DOM change that shows the Online pill, so a flash too brief for an
     assertion to catch still fails the test. */
  await page.evaluate(() => {
    const seen: boolean[] = []
    ;(window as unknown as { __seenOnline: boolean[] }).__seenOnline = seen
    new MutationObserver(() => {
      seen.push([...document.querySelectorAll('span')].some((el) => el.textContent === 'Online'))
    }).observe(document.body, { subtree: true, childList: true, characterData: true })
  })

  await context.setOffline(true)
  await expect(pill(page, 'Offline')).toBeVisible()

  await answerHealth(page, 'down')
  await context.setOffline(false)
  await expect(pill(page, 'Offline')).toBeVisible()
  await page.waitForTimeout(500)

  const seen = await page.evaluate(() => (window as unknown as { __seenOnline: boolean[] }).__seenOnline)
  expect(seen).not.toContain(true)
})

test('a captive portal answering 200 with HTML reads as offline', async ({ page }) => {
  await answerHealth(page, 'portal')
  await signIn(page)
  await expect(pill(page, 'Offline')).toBeVisible()
  expect(await page.evaluate(() => navigator.onLine)).toBe(true)
})

test('the probe is one shared poll and never carries a token', async ({ page }) => {
  const requests: Record<string, string>[] = []
  await page.route('**/api/health', (route) => {
    requests.push(route.request().headers())
    return route.fulfill(healthy)
  })
  await signIn(page)
  await expect(pill(page, 'Online')).toBeVisible()

  /* The top bar, the marking grid, and the assistant dialog all use the hook;
     one page load must still be one probe. */
  expect(requests).toHaveLength(1)
  expect(requests[0].authorization).toBeUndefined()
})

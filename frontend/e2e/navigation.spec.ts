import { expect, test } from '@playwright/test'
import { signIn } from './support'

/* The drawer peeks only when the pointer rests on the hamburger, never from the screen's left
   edge and never from a pointer merely passing over it; a click pins it. */

test('the drawer peeks from a pause on the hamburger, not from the left edge or a passing pointer', async ({ page }) => {
  await signIn(page)
  const drawer = page.getByRole('complementary', { name: 'Main navigation', includeHidden: true })
  const menu = page.getByRole('button', { name: 'Open navigation' })

  await page.mouse.move(2, 400)
  await page.waitForTimeout(400)
  await expect(drawer).toHaveAttribute('aria-hidden', 'true')

  const box = (await menu.boundingBox())!
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  await page.mouse.move(box.x + box.width / 2, box.y + box.height + 200)
  await page.waitForTimeout(400)
  await expect(drawer).toHaveAttribute('aria-hidden', 'true')

  await menu.hover()
  await expect(drawer).toHaveAttribute('aria-hidden', 'false')
})

import { expect, test } from '@playwright/test'
import { E2E_SYNC_CHANGES, signIn, type E2ESyncChange } from './support'

/* The marking grid on a narrow screen: its criteria scroll sideways under the pinned Student column.
   Moving between cells with the keyboard must bring the cell clear of that column, not leave it
   underneath the names for the teacher to scroll to by hand. */

const FIVE_CRITERIA: E2ESyncChange[] = [
  ...E2E_SYNC_CHANGES,
  ...['Vocabulary', 'Grammar', 'Writing', 'Speaking'].map((name, i) => ({
    table: 'criteria', recordId: `criterion-${i + 2}`, version: 1, fields: { subjectId: 'subject-1', name, maxScore: 20 },
  })),
]

test('Shift+Tab back to the first criterion brings it out from under the pinned names', async ({ page }) => {
  await page.setViewportSize({ width: 640, height: 720 })
  await signIn(page, FIVE_CRITERIA)
  await page.goto('/assessments/a1/grid')
  await page.getByRole('button', { name: 'Edit' }).click()

  const cells = page.getByRole('textbox', { name: /Mark out of/ })
  await expect(cells).toHaveCount(5)
  await cells.last().click()
  for (let i = 0; i < 4; i++) await page.keyboard.press('Shift+Tab')
  await expect(cells.first()).toBeFocused()

  const pinned = await page.getByRole('rowheader').first().boundingBox()
  const first = await cells.first().boundingBox()
  expect(first!.x).toBeGreaterThanOrEqual(pinned!.x + pinned!.width)
})

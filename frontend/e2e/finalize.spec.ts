import { expect, test } from '@playwright/test'
import { E2E_SYNC_CHANGES, E2E_USER, signIn, type E2ESyncChange } from './support'

/* Finalizing belongs to the assessment's creator or a teacher assigned to its class and subject
   (docs/spec/access-model.md). The device knows both, so it offers Finalize only to them, rather
   than queue a finalize the server refuses as forbidden. The API is faked; the server's own rule
   is its tests. */

const MARKED = {
  table: 'marks', recordId: 'mark-1', version: 1,
  fields: { assessmentId: 'a1', studentId: 'student-1', criterionId: 'criterion-1', markKind: 'score', score: 16, lastEditedBy: E2E_USER.id },
}

/* a1, complete, created by a colleague; whether the signed-in teacher is assigned to its class
   and subject is the one difference between the two tests. */
function school(assigned: boolean): E2ESyncChange[] {
  return [
    ...E2E_SYNC_CHANGES
      .filter((change) => assigned || change.recordId !== 'assignment-1')
      .map((change) => change.recordId === 'a1' ? { ...change, fields: { ...change.fields, createdBy: 'colleague-1' } } : change),
    MARKED,
  ]
}

test('a complete assessment someone else made, in a class and subject this teacher is not assigned, offers no Finalize', async ({ page }) => {
  await signIn(page, school(false))

  await page.goto('/assessments/a1/grid')
  await expect(page.getByText('16', { exact: true }).first()).toBeVisible()
  await expect(page.getByRole('button', { name: 'Finalize' })).toHaveCount(0)
})

test('a teacher assigned to its class and subject is offered Finalize, though a colleague made it', async ({ page }) => {
  await signIn(page, school(true))

  await page.goto('/assessments/a1/grid')
  await expect(page.getByRole('button', { name: 'Finalize' })).toBeVisible()
})

import { expect, test } from '@playwright/test'
import { E2E_SYNC_CHANGES, E2E_USER, signIn, type E2ESyncChange } from './support'

/* ADR 0014, decision 5: a DOS can close a stream's term while the school calendar still has it
   open, and the server then refuses a new assessment for that stream. The close reaches every
   device on its next sync, so the create dialog shows that stream as unavailable before any marks
   are typed, instead of leaving a failed change to dismiss. The API is faked (support.ts); the
   server's own refusal is covered by the API suite. */

const TWO_STREAMS_ONE_CLOSED: E2ESyncChange[] = [
  ...E2E_SYNC_CHANGES,
  { table: 'classes', recordId: 'class-2', version: 1, fields: { grade: 'Grade 4', stream: '4E', classTeacherId: E2E_USER.id } },
  { table: 'class_subjects', recordId: 'class-subject-2', version: 1, fields: { classId: 'class-2', subjectId: 'subject-1' } },
  { table: 'teacher_assignments', recordId: 'assignment-2', version: 1, fields: { userId: E2E_USER.id, classId: 'class-2', subjectId: 'subject-1' } },
  /* The open term is Term 3, 2025; only 4W's is closed. */
  { table: 'term_closures', recordId: 'closure-3', version: 1, fields: { classId: 'class-1', year: 2025, term: 3, closedBy: 'dos-1', closedAt: '2025-09-04T08:00:00Z' } },
]

test('a stream closed for the open term cannot be chosen, and the open one can', async ({ page, context }) => {
  await signIn(page, TWO_STREAMS_ONE_CLOSED)
  await page.route('**/api/health', (route) => route.abort())
  await context.setOffline(true)

  await page.goto('/assessments')
  await page.getByRole('button', { name: 'New' }).click()
  const dialog = page.getByRole('dialog', { name: 'New Assessment' })
  await dialog.getByRole('button', { name: /English/ }).click()
  await dialog.getByRole('button', { name: 'Continue' }).click()
  await dialog.getByLabel('Assessment name').fill('CAT 3 catch-up')
  await dialog.getByLabel('Assessment date').fill('2025-09-10')
  await dialog.getByRole('button', { name: 'Continue' }).click()

  const closed = dialog.getByRole('button', { name: /Stream 4W/ })
  await expect(closed).toBeDisabled()
  await expect(dialog.getByTestId('stream-closed')).toHaveText(
    "Term 3 is closed for this stream; the Director of Studies or the subject's head of department must unlock it first.",
  )

  /* Nothing chosen yet, and the closed stream cannot make up the numbers. */
  await expect(dialog.getByRole('button', { name: 'Create' })).toBeDisabled()
  await dialog.getByRole('button', { name: /Stream 4E/ }).click()
  await expect(dialog.getByRole('button', { name: 'Create' })).toBeEnabled()
  await dialog.getByRole('button', { name: 'Create' }).click()
  await expect(dialog.getByText('Assessment created')).toBeVisible()
  await expect(dialog.getByText(/Term 3, 2025 · 1 stream · 2025-09-10/)).toBeVisible()
})

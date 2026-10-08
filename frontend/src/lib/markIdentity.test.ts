import { describe, expect, it } from 'vitest'
import { markIdFor, uuidV5 } from './markIdentity'

describe('markIdFor', () => {
  it('implements RFC 4122 UUIDv5', async () => {
    await expect(uuidV5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'www.widgets.com'))
      .resolves.toBe('21f7f8de-8051-5b89-8680-0195ef798b6a')
  })

  it('uses stable cell identity from the assessment, student, and criterion tuple', async () => {
    const first = await markIdFor('assessment-1', 'student-1', 'criterion-1')

    await expect(markIdFor('assessment-1', 'student-1', 'criterion-1')).resolves.toBe(first)
    await expect(markIdFor('assessment-1', 'student-2', 'criterion-1')).resolves.not.toBe(first)
    expect(first).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i)
  })
})
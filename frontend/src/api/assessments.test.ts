import { describe, expect, it, vi } from 'vitest'
import { unlockAssessment } from './assessments'

const reply = (body: unknown, status = 200) => vi.fn(async () => new Response(JSON.stringify(body), { status }))

describe('unlockAssessment', () => {
  it('posts the trimmed note to the assessment\'s unlock, with the token, and sends no note when it is blank', async () => {
    const fetchMock = reply({ data: { id: 'a 1', status: 'scheduled', version: 3 } })

    await expect(unlockAssessment('a 1', '  Correct the score.  ', 'tok', fetchMock as unknown as typeof fetch)).resolves.toMatchObject({ data: { status: 'scheduled' } })
    await unlockAssessment('a 1', '   ', 'tok', fetchMock as unknown as typeof fetch)

    const [path, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(path).toContain('/api/assessments/a%201/unlock')
    expect(init.method).toBe('POST')
    expect(JSON.parse(String(init.body))).toEqual({ note: 'Correct the score.' })
    expect(new Headers(init.headers).get('Authorization')).toBe('Bearer tok')
    expect(JSON.parse(String((fetchMock.mock.calls[1] as unknown as [string, RequestInit])[1].body))).toEqual({})
  })

  it('throws the server\'s message, such as the note being required once cards exist', async () => {
    await expect(unlockAssessment('a1', '', 'tok', reply({ message: 'A resolution note is required when reports exist.' }, 422) as unknown as typeof fetch))
      .rejects.toThrow('A resolution note is required when reports exist.')
  })
})

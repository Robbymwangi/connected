import { describe, expect, it, vi } from 'vitest'
import { getReportDownload } from './reports'
import { closeTerm } from './termClosures'

const reply = (body: unknown, status = 200) => vi.fn(async () => new Response(JSON.stringify(body), { status }))

describe('closeTerm and getReportDownload', () => {
  it('posts the close in the API\'s field names, with the token', async () => {
    const fetchMock = reply({ data: { id: 't1' }, meta: { comments: 3, skipped: 1 } }, 201)

    await expect(closeTerm({ classId: 'c1', year: 2026, term: 2 }, 'tok', fetchMock as unknown as typeof fetch)).resolves.toMatchObject({ meta: { comments: 3, skipped: 1 } })

    const [path, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(path).toContain('/api/term-closures')
    expect(init.method).toBe('POST')
    expect(JSON.parse(String(init.body))).toEqual({ class_id: 'c1', year: 2026, term: 2 })
    expect(new Headers(init.headers).get('Authorization')).toBe('Bearer tok')
  })

  it('throws the server\'s message when the close is refused', async () => {
    await expect(closeTerm({ classId: 'c1', year: 2026, term: 2 }, 'tok', reply({ message: 'This action is unauthorized.' }, 403) as unknown as typeof fetch))
      .rejects.toThrow('This action is unauthorized.')
  })

  it('asks for a report card\'s link by its id', async () => {
    const fetchMock = reply({ data: { url: 'https://storage.test/r.pdf', expiresAt: '2026-10-12T08:10:00Z' } })

    await expect(getReportDownload('r 1', 'tok', fetchMock as unknown as typeof fetch)).resolves.toMatchObject({ data: { url: 'https://storage.test/r.pdf' } })
    expect(String((fetchMock.mock.calls[0] as unknown as [string])[0])).toContain('/api/reports/r%201/download')
  })
})

import { describe, expect, it, vi } from 'vitest'
import { getReportSummary } from './reports'

const response = () => new Response(JSON.stringify({ available_years: [], available_assessments: [], summary: {} }))

describe('getReportSummary', () => {
  it('sends the selected filters and bearer token to the summary endpoint', async () => {
    const fetchMock = vi.fn().mockResolvedValue(response())

    await getReportSummary({
      stream: '4 West',
      year: 2026,
      subjectId: 'subject-id',
      term: 2,
      assessmentName: 'CAT 1 / Mid-term',
      assessmentId: 'assessment-id',
    }, 'device-token', fetchMock)

    const [path, init] = fetchMock.mock.calls[0]
    const url = new URL(path, 'http://localhost')
    expect(url.pathname).toBe('/api/reports/summary')
    expect(url.searchParams.get('stream')).toBe('4 West')
    expect(url.searchParams.get('year')).toBe('2026')
    expect(url.searchParams.get('subject_id')).toBe('subject-id')
    expect(url.searchParams.get('term')).toBe('2')
    expect(url.searchParams.get('assessment_name')).toBe('CAT 1 / Mid-term')
    expect(url.searchParams.get('assessment_id')).toBe('assessment-id')
    expect((init.headers as Headers).get('Authorization')).toBe('Bearer device-token')
  })

  it('omits subject, term, and assessment filters for an overall year query', async () => {
    const fetchMock = vi.fn().mockResolvedValue(response())

    await getReportSummary({ stream: '4 West', year: 2026 }, 'device-token', fetchMock)

    const [path] = fetchMock.mock.calls[0]
    const url = new URL(path, 'http://localhost')
    expect([...url.searchParams.entries()]).toEqual([['stream', '4 West'], ['year', '2026']])
  })
})
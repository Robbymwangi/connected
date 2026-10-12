import { describe, expect, it, vi } from 'vitest'
import { askAssistant, isAssistantUnavailable } from './assistant'

const reply = (body: unknown, status = 200) => vi.fn(async () => new Response(JSON.stringify(body), { status }))

describe('askAssistant', () => {
  it('sends the report\'s scope, the question, and the conversation id, and nothing else', async () => {
    const fetchMock = reply({ data: { conversationId: 'c1', answerable: true, text: 'Steady.', chart: null } })

    await askAssistant({ stream: '4W', year: 2026, subjectId: 'sub', term: 2, assessmentName: null, assessmentId: null }, 'How did they do?', 'c0', 'tok', fetchMock as unknown as typeof fetch)

    const [path, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(path).toContain('/api/reports/assistant')
    expect(JSON.parse(String(init.body))).toEqual({
      stream: '4W', year: 2026, subject_id: 'sub', term: 2, assessment_name: null, assessment_id: null, question: 'How did they do?', conversation_id: 'c0',
    })
  })

  it('tells a server with no assistant apart from any other failure', async () => {
    const unavailable = askAssistant({ stream: '4W', year: 2026 }, 'q', null, 'tok', reply({ message: 'x', code: 'assistant_unavailable' }, 503) as unknown as typeof fetch)
    await expect(unavailable).rejects.toSatisfy(isAssistantUnavailable)

    const down = askAssistant({ stream: '4W', year: 2026 }, 'q', null, 'tok', reply({ message: 'x' }, 503) as unknown as typeof fetch)
    await expect(down).rejects.not.toSatisfy(isAssistantUnavailable)
  })
})

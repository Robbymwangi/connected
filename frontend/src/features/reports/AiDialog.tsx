import { CloudOff, Send, Sparkles } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { askAssistant, isAssistantUnavailable } from '../../api/assistant'
import type { ReportSummaryFilters } from '../../api/reports'
import { LineChart } from '../../components/charts'
import { Modal } from '../../components/Modal'
import { PASS_MARK_PCT } from '../../lib/analytics'
import { answer, SUGGESTIONS, type Answer, type AssistantReport } from '../../lib/assistant'
import { useConnectivity } from '../../lib/connectivity'
import { BarList } from './BarList'

type Message = { role: 'user'; text: string } | { role: 'assistant'; answer: Answer; standIn?: boolean; failed?: boolean }

type AiDialogProps = {
  open: boolean
  onClose: () => void
  report: AssistantReport
  /* The scope the report on screen was asked for; the assistant answers within it. */
  filters: ReportSummaryFilters | null
  token: string
}

/* Questions about the report on screen (ADR 0013). Needs a connection: the assistant runs on
   the server, so offline the dialog says so and offers nothing else. Each question is a live
   call that the dialog waits on; the server keeps the conversation and returns its id. A server
   without a model key says so, and the dialog falls back to the stand-in templated from the
   report's own figures, labelled as such. */
export function AiDialog({ open, onClose, report, filters, token }: AiDialogProps) {
  const { isOnline } = useConnectivity()
  const [messages, setMessages] = useState<Message[]>([])
  const [input, setInput] = useState('')
  const [thinking, setThinking] = useState(false)
  /* The ring turns twice when the dialog opens, keeps turning while thinking, and
     is off otherwise. The dialog is remounted per opening, so 'once' is initial. */
  const [ring, setRing] = useState<'once' | 'live' | 'off'>('once')
  const conversation = useRef<string | null>(null)
  const mounted = useRef(true)
  const endRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false }
  }, [])
  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'end' })
  }, [messages, thinking])

  const ask = (question: string) => {
    const q = question.trim()
    if (!q || thinking || !isOnline) return
    setMessages((m) => [...m, { role: 'user', text: q }])
    setInput('')
    setThinking(true)
    setRing('live')
    void reply(q).then((message) => {
      if (!mounted.current) return
      setMessages((m) => [...m, message])
      setThinking(false)
      setRing('off')
    })
  }

  const reply = async (q: string): Promise<Message> => {
    if (!filters) return { role: 'assistant', answer: answer(q, report), standIn: true }
    try {
      const { data } = await askAssistant(filters, q, conversation.current, token)
      conversation.current = data.conversationId
      return { role: 'assistant', answer: { text: data.text, chart: data.chart ?? undefined } }
    } catch (error) {
      if (isAssistantUnavailable(error)) return { role: 'assistant', answer: answer(q, report), standIn: true }
      return { role: 'assistant', answer: { text: 'The assistant could not answer just now. Check the connection and ask again.' }, failed: true }
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ask about this report"
      size="md"
      headerExtra={<Sparkles className="size-4 text-primary" />}
      frameClassName={`orbit ${ring === 'live' ? 'orbit--live' : ring === 'once' ? 'orbit--once' : ''}`}
    >
      <div className="-mx-6 -my-5 flex max-h-[70vh] flex-col">
        {!isOnline ? (
          <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
            <div className="flex size-12 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground">
              <CloudOff className="size-5" />
            </div>
            <p className="text-sm font-semibold text-foreground">This needs a connection</p>
            <p className="max-w-xs text-xs leading-relaxed text-muted-foreground">
              The assistant runs on the server. Marking keeps working offline; reports and this dialog need a connection.
            </p>
          </div>
        ) : (
          <>
            <div className="flex-1 overflow-y-auto px-5 py-4">
              <p className="mb-4 rounded-lg border border-border bg-muted/40 px-3 py-2 text-[11px] leading-snug text-muted-foreground">
                Answers come only from the figures of this report ({report.scopeLabel}). Students reach the model as S1, S2, never by name.
              </p>
              {messages.length === 0 && (
                <div>
                  <p className="mb-2 text-[10px] font-bold tracking-wider text-muted-foreground uppercase">Suggested questions</p>
                  <div className="flex flex-col gap-1.5">
                    {SUGGESTIONS.map((q) => (
                      <button key={q} type="button" onClick={() => ask(q)} className="rounded-xl border border-border bg-muted/30 px-3 py-2 text-left text-sm text-foreground transition-colors hover:bg-muted/60">
                        {q}
                      </button>
                    ))}
                  </div>
                </div>
              )}
              <div className="flex flex-col gap-3" aria-live="polite">
                {messages.map((m, i) =>
                  m.role === 'user' ? (
                    <p key={i} className="ml-8 self-end rounded-2xl rounded-br-md bg-primary px-3 py-2 text-sm text-primary-foreground">{m.text}</p>
                  ) : (
                    <div key={i} className="mr-8 rounded-2xl rounded-bl-md border border-border bg-card px-3 py-2.5 text-sm leading-relaxed text-foreground">
                      <p className={m.failed ? 'text-muted-foreground' : undefined}>{m.answer.text}</p>
                      {m.standIn && (
                        <p className="mt-1.5 text-[11px] text-warning">Stand-in: this server has no assistant, so the answer is templated from the report on screen.</p>
                      )}
                      {m.answer.chart === 'trend' && report.trend.length >= 2 && (
                        <div className="mt-3">
                          <LineChart label={`Pass rate per assessment for ${report.scopeLabel}`} categories={report.trend.map((t) => t.label)} series={[{ id: 'p', label: 'Pass rate', color: 'primary', values: report.trend.map((t) => t.passRate) }]} max={100} format={(v) => `${Math.round(v)}%`} referenceLine={{ value: PASS_MARK_PCT, label: 'Pass mark' }} height={130} />
                        </div>
                      )}
                      {m.answer.chart === 'criteria' && report.criteria.length > 0 && (
                        <div className="mt-3">
                          <BarList rows={[...report.criteria].sort((a, b) => b.pct - a.pct).map((c) => ({ label: c.name, pct: c.pct }))} reference={PASS_MARK_PCT} />
                        </div>
                      )}
                    </div>
                  ),
                )}
                {thinking && (
                  <p className="mr-8 flex items-center gap-1.5 rounded-2xl rounded-bl-md border border-border bg-card px-3 py-2.5 text-xs text-muted-foreground" role="status">
                    <span className="size-1.5 animate-bounce rounded-full bg-muted-foreground" />
                    <span className="size-1.5 animate-bounce rounded-full bg-muted-foreground [animation-delay:120ms]" />
                    <span className="size-1.5 animate-bounce rounded-full bg-muted-foreground [animation-delay:240ms]" />
                    <span className="sr-only">Thinking</span>
                  </p>
                )}
                <div ref={endRef} />
              </div>
            </div>
            <form
              className="flex items-center gap-2 border-t border-border/60 px-4 py-3"
              onSubmit={(e) => {
                e.preventDefault()
                ask(input)
              }}
            >
              <input
                value={input}
                onChange={(e) => setInput(e.target.value)}
                placeholder={messages.length === 0 ? 'Or ask your own question' : 'Ask a follow-up'}
                aria-label="Your question"
                disabled={thinking}
                className="flex-1 bg-transparent text-sm text-foreground placeholder:text-muted-foreground focus:outline-none disabled:opacity-50"
              />
              <button type="submit" disabled={!input.trim() || thinking} aria-label="Send" className="rounded-lg bg-primary p-2 text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-40">
                <Send className="size-4" />
              </button>
            </form>
          </>
        )}
      </div>
    </Modal>
  )
}

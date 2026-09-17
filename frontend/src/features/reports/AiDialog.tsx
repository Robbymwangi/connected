import { CloudOff, Send, Sparkles } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { LineChart } from '../../components/charts'
import { Modal } from '../../components/Modal'
import { PASS_MARK_PCT } from '../../lib/analytics'
import { answer, SUGGESTIONS, type Answer, type AssistantReport } from '../../lib/assistant'
import { useConnectivity } from '../../lib/connectivity'
import { BarList } from './BarList'

type Message = { role: 'user'; text: string } | { role: 'assistant'; answer: Answer }

type AiDialogProps = {
  open: boolean
  onClose: () => void
  report: AssistantReport
}

/* Time the stand-in takes to "think", so the loading state is real enough to design
   against. The real assistant is a server call. */
const THINK_MS = 700

/* Questions about the report on screen. Needs a connection: the assistant runs on
   the server, so offline the dialog says so and offers nothing else. Answers are
   the stand-in's until the API exists, and are labelled as such. */
export function AiDialog({ open, onClose, report }: AiDialogProps) {
  const { isOnline } = useConnectivity()
  const [messages, setMessages] = useState<Message[]>([])
  const [input, setInput] = useState('')
  const [thinking, setThinking] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const endRef = useRef<HTMLDivElement>(null)

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current)
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
    timer.current = setTimeout(() => {
      setMessages((m) => [...m, { role: 'assistant', answer: answer(q, report) }])
      setThinking(false)
    }, THINK_MS)
  }

  return (
    <Modal open={open} onClose={onClose} title="Ask about this report" size="md" headerExtra={<Sparkles className="size-4 text-primary" />}>
      <div className="-mx-6 -my-5 flex max-h-[70vh] flex-col">
        {!isOnline ? (
          <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
            <div className="flex size-12 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground">
              <CloudOff className="size-5" />
            </div>
            <p className="text-sm font-semibold text-foreground">This needs a connection</p>
            <p className="max-w-xs text-xs leading-relaxed text-muted-foreground">
              The assistant runs on the server. Marking and reports keep working offline; come back online to ask questions about them.
            </p>
          </div>
        ) : (
          <>
            <div className="flex-1 overflow-y-auto px-5 py-4">
              <p className="mb-4 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-[11px] leading-snug text-foreground/80">
                <strong className="text-warning">Stand-in.</strong> Answers are templated from the report on screen ({report.scopeLabel}) so they agree with it. The assistant proper arrives with the API.
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
                      <p>{m.answer.text}</p>
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

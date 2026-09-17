import type { Student } from '../fixtures/students'
import { PASS_MARK_PCT, type Summary } from './analytics'

/* A stand-in for the report assistant. The real one is a server-side call and
   arrives with the API; until then, answers are sentences templated from the
   report on screen, so what the assistant says agrees with the figures beside it.
   Nothing here guesses: every number comes from the Summary it is handed. */

export type AssistantReport = {
  scopeLabel: string
  summary: Summary
  criteria: Array<{ name: string; pct: number }>
  trend: Array<{ label: string; passRate: number | null; meanPct: number | null }>
  attention: Array<{ studentId: string; meanPct: number; latestPct: number }>
  roster: Student[]
}

export type Answer = {
  text: string
  /* A chart the dialog may draw beneath the text. */
  chart?: 'trend' | 'criteria'
}

export const SUGGESTIONS = [
  'Summarise performance in this scope',
  'Which students need the most support?',
  'How is the trend across assessments?',
  'What are the strongest and weakest criteria?',
] as const

const pct = (v: number | null) => (v === null ? 'no data' : `${Math.round(v)}%`)

function summarise(r: AssistantReport): Answer {
  const s = r.summary
  if (s.scored === 0) return { text: `There are no marked results in ${r.scopeLabel} yet, so there is nothing to summarise.` }
  const meeting = s.levels.EE + s.levels.ME
  return {
    text: `${r.scopeLabel}: ${pct(s.passRate)} of ${s.scored} results are at or above the ${PASS_MARK_PCT}% pass mark, with a mean of ${pct(s.meanPct)}. ${meeting} are meeting or exceeding expectations, ${s.levels.AE} approaching, ${s.levels.BE} below.${s.absent ? ` ${s.absent} absence${s.absent === 1 ? '' : 's'} excluded.` : ''}`,
    chart: r.trend.length >= 2 ? 'trend' : undefined,
  }
}

function support(r: AssistantReport): Answer {
  if (r.summary.scored === 0) return { text: `There are no marked results in ${r.scopeLabel} yet, so I cannot say who needs support.` }
  if (r.attention.length === 0) return { text: `Nobody in ${r.scopeLabel} is averaging below the pass mark.` }
  const name = (id: string) => r.roster.find((st) => st.id === id)?.name ?? id
  const top = r.attention.slice(0, 3).map((a) => `${name(a.studentId)} (avg ${Math.round(a.meanPct)}%, latest ${Math.round(a.latestPct)}%)`)
  return {
    text: `${r.attention.length} student${r.attention.length === 1 ? ' is' : 's are'} averaging below ${PASS_MARK_PCT}% in ${r.scopeLabel}. Lowest first: ${top.join('; ')}.${r.attention.length > 3 ? ` The full list is in the "Students needing attention" panel.` : ''}`,
  }
}

function trend(r: AssistantReport): Answer {
  if (r.trend.length < 2) return { text: `A trend needs at least two assessments in scope; ${r.scopeLabel} has ${r.trend.length}.` }
  const first = r.trend[0]
  const last = r.trend[r.trend.length - 1]
  const delta = first.passRate !== null && last.passRate !== null ? Math.round(last.passRate - first.passRate) : null
  const direction = delta === null ? 'cannot be compared' : delta > 0 ? `up ${delta} points` : delta < 0 ? `down ${-delta} points` : 'unchanged'
  return {
    text: `Across ${r.trend.length} assessments in ${r.scopeLabel}, the pass rate went from ${pct(first.passRate)} (${first.label}) to ${pct(last.passRate)} (${last.label}): ${direction}. The mean went from ${pct(first.meanPct)} to ${pct(last.meanPct)}.`,
    chart: 'trend',
  }
}

function criteria(r: AssistantReport): Answer {
  if (r.criteria.length === 0) return { text: `Criterion detail comes from marked grids, and ${r.scopeLabel} has none in scope.` }
  const sorted = [...r.criteria].sort((a, b) => b.pct - a.pct)
  const best = sorted[0]
  const worst = sorted[sorted.length - 1]
  return {
    text: `Strongest criterion in ${r.scopeLabel} is ${best.name} at ${pct(best.pct)}; weakest is ${worst.name} at ${pct(worst.pct)}.${sorted.length > 2 ? ` ${sorted.slice(1, -1).map((c) => `${c.name} ${pct(c.pct)}`).join(', ')} in between.` : ''}`,
    chart: 'criteria',
  }
}

/* Answers the four suggested questions exactly, and routes free text by keyword.
   Anything it cannot place gets the summary, and says so. */
export function answer(question: string, r: AssistantReport): Answer {
  const q = question.trim().toLowerCase()
  if (q === SUGGESTIONS[0].toLowerCase() || /summar|overview|overall/.test(q)) return summarise(r)
  if (q === SUGGESTIONS[1].toLowerCase() || /support|struggl|attention|below|weak(est)? student/.test(q)) return support(r)
  if (q === SUGGESTIONS[2].toLowerCase() || /trend|previous|last term|over time|improv|progress/.test(q)) return trend(r)
  if (q === SUGGESTIONS[3].toLowerCase() || /criteri|rubric|(strong|weak)(est)?\s+(area|skill|part)/.test(q)) return criteria(r)
  const fallback = summarise(r)
  return { ...fallback, text: `I can only answer about the report on screen for now. Here is the summary: ${fallback.text}` }
}


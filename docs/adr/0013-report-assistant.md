# 13. The report assistant answers from the analytics service, through tools, inside its report

Date: 2026-10-12

## Status

Proposed. Decision 4 is provisional: it stands until the advisory on sending student
data to a model provider (Angaza, or the lecturer coordinator) is received, and
changes with it (Consequences).

## Context

AGENTS.md places an assistant in Reports, scoped to the report on screen, with the
same connectivity requirement as the screen; until the API's assistant exists,
`lib/assistant.ts` templates answers from the report's own figures and labels them as
a stand-in. `docs/spec/analytics.md` fixes the rest of what is settled: every figure is
computed once, server-side, by the analytics service, and each question is its own live
call. Nothing yet says how the real assistant reaches the figures, what it may say, or
what leaves the building.

Two pressures shape it. A teacher asks about percentages, pass rates, and who needs
support, so an assistant that sees only performance levels cannot answer most of what
it is for. And a general chat model behind a text box is routinely talked into answering
anything at all, which for a school tool means off-topic output with the school's name
on it.

ADR 0012 decision 5 says percentages and class position never appear in anything sent
to the model. It was written for comments and report cards, which reach families; this
ADR narrows it for the assistant (decision 4).

## Decision

1. **The assistant reads the analytics service, never the database.** It is given a
   small set of read-only tools, each a thin wrapper over `AnalyticsQueryService`:
   the summary, the trend, the criterion breakdown, the attention list, and one
   student's outcomes. There is no SQL tool and no table is described to the model.
   Every tool runs with the asking teacher's institution (the global scope) and with
   the scope and filters of the report on screen fixed by the server; the model may
   narrow within them, never widen them.

2. **The server runs the loop.** `POST /api/reports/assistant` carries the report's
   scope and filters and the new question. The server calls the model with the tool
   definitions, runs each tool the model asks for, returns the results, and repeats
   until there is an answer, with a cap of about five tool calls, a timeout, and a
   per-user rate limit. The loop is written on Laravel's HTTP client, as ADR 0007 does
   for the batch API, so every step is ours to read and test.

3. **Scope is enforced by code, not only by the prompt.**
   - **Capability.** The model has the report tools and nothing else: no web, no code
     execution. A model talked off topic still cannot fetch or read anything new.
   - **Structured output.** Every answer must match a schema:
     `{answerable, text, chart, figures_used}`. When `answerable` is false the server
     discards the text and shows a fixed reply, "I can only answer questions about this
     report", so a refusal is never the model's own words.
   - **Grounding check.** Every number in `text` must appear in a tool result from the
     same request. An answer with no figures, or a figure no tool produced, is replaced
     by the fixed reply. This is plain PHP, unit tested, and it is what makes an
     off-topic answer, which cites nothing from the report, detectable rather than
     merely discouraged.
   - **Server-held history.** The server keeps the last few turns of a conversation for
     a short time; the client sends only the new question. A client that sent its own
     history could forge earlier assistant turns.
   - **A red-team evaluation before release**: a fixed set of off-topic requests,
     instruction overrides, role play, requests for other schools' data, and attempts to
     extract names, run against the real model, with the pass rate recorded in the merge
     request that enables it.

4. **What leaves the building (provisional).** The assistant's tools return figures,
   including percentages, but never a student's name or admission number: students are
   handles (`S1`, `S2`) for the length of one request, and the server puts names back in
   the answer before it reaches the screen. No school name is sent. This is the same
   pseudonymising boundary as comment prompts, held in the tool layer. It narrows ADR
   0012 decision 5 for this one surface: that rule continues to hold for comments,
   report cards, and notifications.

5. **The model is Claude Sonnet 5.5 (`claude-sonnet-5-5`)**, called live, not in a
   batch, because a teacher is waiting for the answer. Comment generation stays on
   Claude Haiku 5.5 in the batch API (ADR 0007).

6. **The answer keeps the stand-in's shape.** `{text, chart}` is what `AiDialog`
   already renders, so replacing `lib/assistant.ts` changes where answers come from,
   not the dialog. The dialog's connectivity rule is unchanged.

## Considered and rejected

- **Levels only (no percentages to the model).** Keeps ADR 0012 decision 5 whole, but
  the assistant could not answer pass rates, means, or the attention list, which is most
  of what Reports shows; it would be a stand-in with a bill.
- **Names and figures, unmasked.** Simplest, and the answer needs no mapping back, but it
  sends identifiable student data to a third party before the advisory says that is
  acceptable.
- **A SQL tool, or a text-to-SQL step.** Flexible, but the model would see the schema,
  could write a query the analytics service does not, and the institution scope would
  rest on the query it wrote rather than on the global scope.
- **A system prompt as the only scope control.** The failure this ADR exists to prevent
  is the one a prompt alone does not stop.
- **The official PHP SDK and its tool runner.** Less code, but a new dependency, and the
  loop it hides is short enough to own and worth being able to explain.
- **Claude Opus 5.5.** More capable than answering about one report needs, at twice the
  price.

## Consequences

- Reversing decision 4 is contained to the tool layer: levels only means the tools stop
  returning percentages; unmasked means the handle mapping is removed. Either is a merge
  request or two, not a redesign.
- The grounding check rejects some legitimate answers that cite no figure (a definition
  of a performance level, say). That is the accepted cost; the fixed reply says what the
  assistant can do, and the evaluation will show how often it happens.
- Each question costs a live model call with tool round trips; the rate limit and the
  tool cap bound it per teacher.
- Building it is `docs/build-plan.md` item 4.5, after 4.4. Until it lands the labelled
  stand-in remains.

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
   student's outcomes, and since decision 7 the ten that answer broader questions.
   There is no SQL tool and no table is described to the model. Every tool runs with
   the asking teacher's institution (the global scope) and starts from the scope and
   filters of the report on screen fixed by the server; the model may narrow within
   them, and only the tools decision 7 names reach further, each by its own fixed rule.

2. **The server runs the loop.** `POST /api/reports/assistant` carries the report's
   scope and filters and the new question. The server calls the model with the tool
   definitions, runs each tool the model asks for, returns the results, and repeats
   until there is an answer, with a cap on tool calls (twelve since decision 7), a
   timeout, and a per-user rate limit. The loop is written on Laravel's HTTP client, as ADR 0007 does
   for the batch API, so every step is ours to read and test.

3. **Scope is enforced by code, not only by the prompt.**
   - **Capability.** The model has the report tools and nothing else: no web, no code
     execution. A model talked off topic still cannot fetch or read anything new.
   - **A question-scope gate, before the answer.** Each question is first put to a
     separate, cheap call (Claude Haiku 5.5, no tools, no report data, no history) whose
     only job is to classify it as about the report on screen or not, through a
     one-field structured output. Anything other than a clear "in scope" (out of scope,
     unsure, a refusal, an error, a timeout) gets the fixed reply, "I can only answer
     questions about this report", and the main call is never made. The gate fails
     closed, and it is not the model that writes the answer judging itself. It judges the
     question, not the answer: a question that is partly about the report, or one that
     tries to steer the main model once it is answering, gets past it, and is left to the
     two checks below and to the evaluation.
   - **Structured output.** Every answer must match a schema:
     `{answerable, text, chart, figures_used}`. When `answerable` is false the server
     discards the text and shows the same fixed reply, so a refusal is never the model's
     own words. This field is a second line, not the scope control: the model that writes
     the answer also sets it.
   - **Grounding check.** Every number in `text` must appear in a tool result from the
     same request, and an answer must cite at least one; otherwise the fixed reply is
     shown. This is plain PHP, unit tested. It keeps figures honest; it does not on its
     own prove an answer is on topic, since an off-topic reply could repeat a figure,
     which is why the scope gate comes first.
   - **Server-held history.** The server keeps the last few turns of a conversation for
     a short time; the client sends only the new question. A client that sent its own
     history could forge earlier assistant turns. What is kept is exactly what the
     provider saw, in handle form, never the name-restored answers (decision 4).
   - **A red-team evaluation before release**: a fixed set of off-topic requests,
     instruction overrides, role play, requests for other schools' data, attempts to
     extract names, questions that mix the report with something else, and questions that
     try to redirect the main model, run against the real model, alongside a set of ordinary questions
     about a report. The bar: no attack yields off-topic content, a student name, or
     another school's data, and at least 90 percent of the ordinary questions get an
     answer rather than the fixed reply. The merge request that enables the assistant
     records both figures, and does not merge until both pass.

4. **What leaves the building (provisional).** The assistant's tools return figures,
   including percentages, but never a student's name or admission number: students are
   handles (`S1`, `S2`) for the length of one conversation, and the server puts names
   back in the answer before it reaches the screen. The handle-to-student mapping is held
   with the conversation's history and expires with it, so a handle names the same
   student on every retained turn, and no retained turn outlives the mapping that gives
   its handles meaning. The teacher's own question is the other way a name could
   leave: a roster student's full name, or a part of it no other student shares and
   written with its capital, becomes that student's handle before either model sees
   the question, and the question is kept in that form. A name typed in lower case,
   or one the roster does not hold, is not caught. No school name is sent. This is the same
   pseudonymising boundary as comment prompts, held in the tool layer. It narrows ADR
   0012 decision 5 for this one surface: that rule continues to hold for comments,
   report cards, and notifications.

5. **The model is Claude Sonnet 5.5 (`claude-sonnet-5-5`)**, called live, not in a
   batch, because a teacher is waiting for the answer. Comment generation stays on
   Claude Haiku 5.5 in the batch API (ADR 0007).

6. **The answer keeps the stand-in's shape.** `{text, chart}` is what `AiDialog`
   already renders, so the rendering is unchanged. The call is not: today
   `answer(q, report)` returns at once, and the live assistant is a request, so
   `AiDialog` awaits it, shows that it is waiting, and shows the fixed reply or an
   offline message if it fails. Its connectivity rule is unchanged.

7. **Broader questions, within the school (added 2026-10-14).** Physical testing showed
   what teachers ask first: who is doing best, whether a student struggles only in one
   subject, whether the stream did better than its sister streams. Those are the simple
   statistics school decisions rest on, and Kenyan exam analysis already reports them:
   subject means, deviation from the last exam, the head and tail of the class, stream
   against stream, girls against boys. The report's own scope cannot answer them, so ten
   tools are added. Reading is not the limit: any teacher may read every stream and mark
   in their school (`docs/spec/access-model.md`). What decision 1 guarded against was the
   model choosing where to look, and that stays closed: no tool takes a stream, subject,
   year, or school, and each widening is one rule in `ReportTools`.
   - **Within the report's scope:** class standings (every student, highest mean first,
     with the class's top, middle, and bottom third), improvers (the mirror of the decline
     list), and absences and marks not yet entered.
   - **The stream's other subjects,** in the report's year and term: one student's mean in
     each subject beside the stream's, the students below the pass mark in two or more
     subjects, and each subject's mean, pass rate, and levels.
   - **The term before,** in the same year only: each subject's mean and pass rate against
     the previous term, the deviation. A stream's name passes to next year's cohort, so
     last year's figures for it are other children and are never compared.
   - **The other streams of the same grade,** at the report's subject, year, and term:
     each stream's mean, pass rate, and entries, and each rubric criterion's mean per
     stream. When the report is one assessment, every stream is held to the assessment of
     that name; otherwise the result says whether the streams sat the same ones.
   - **Girls and boys as two groups:** totals only, never a student's sex. A group of
     fewer than five students is withheld: too small to compare fairly, and close to
     describing one or two children.
   - **Differences arrive worked out.** A comparison is a difference, and the model may
     not do arithmetic, so every comparing tool returns its gaps; the grounding check
     accepts a returned difference written without its sign ("6.2 points behind").
   - **Never teachers.** No tool says who teaches a stream, and the gate treats judging
     or comparing teachers as out of scope. Stream against stream is about the children's
     results, not staff appraisal.
   - The red-team evaluation gains ordinary questions of each kind and a "judging
     teachers" attack set, against the same bar.

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
- **Comparing with other schools, or with last year's cohort of a stream.** The first
  leaves the school and its global scope; the second compares different children under
  the same stream name.
- **Ranking or comparing teachers.** A stream's results are not a fair measure of the
  person teaching it, and the assistant is not an appraisal tool.
- **Claude Opus 5.5.** More capable than answering about one report needs, at twice the
  price.

## Consequences

- Reversing decision 4 is contained to the tool layer: levels only means the tools stop
  returning percentages; unmasked means the handle mapping is removed. Either is a merge
  request or two, not a redesign.
- The grounding check rejects some legitimate answers that cite no figure (a definition
  of a performance level, say). That is the accepted cost; the fixed reply says what the
  assistant can do, and the evaluation will show how often it happens.
- The scope gate adds a second, small Haiku call to every question, and can turn away a
  legitimate but oddly worded one; the evaluation's ordinary-question rate measures that.
- Each question costs a live model call with tool round trips; the rate limit and the
  tool cap bound it per teacher.
- Building it is `docs/build-plan.md` item 4.5, after 4.4. Until it lands the labelled
  stand-in remains.

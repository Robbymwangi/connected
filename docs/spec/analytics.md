# Analytics

Every figure here is already computed and tested in `frontend/src/lib/analytics.ts`,
today's only implementation, since there is no API yet. That code is this file's
source for what each figure means, but not for where it's computed going forward:
`docs/build-plan.md` item 4.1 is explicit that these are server-side SQL
aggregates, "do not compute these on the client or in PHP memory," and this file
now holds to that literally (see Where figures are computed). The TypeScript
retires once item 4.2 wires the real screens to the API; until then it remains
the definitions' one working reference.

## Definitions

An outcome is one student's result on one assessment: `scored` (a total, a
maximum, and a percentage of that maximum), `absent`, or `missing`. A
complete row of scores is `scored`; a row with any absent cell is `absent`;
an incomplete row falls back to the `results` record for that student and
assessment if one exists, and is `missing` only if neither the grid nor a
result can answer it (`outcomesFor`).

Every figure is a percentage of the rubric maximum, never a raw total, so
assessments with different maxima (English's 60, Maths' 100, Science's 100,
`fixtures/rubrics.ts`) combine on one scale. `PASS_MARK_PCT` is `50`, a
constant, not a per-institution setting, for the reasons given under
Decisions below.

An absent outcome is excluded from every denominator except entry
completeness, where it's counted as a recorded fact rather than a gap
(AGENTS.md's explicit-predicate rule for absence). A missing outcome is
excluded everywhere, including completeness: a mark nobody entered is not
information about the student.

Assessments in scope are filtered to a stream, a subject or `Overall`, a
term (or `Year to Date`, spanning every term), an assessment name (or every
name), and never a `scheduled` assessment, since one that hasn't started
has no outcomes to report (`assessmentsInScope`). In scope, ordered by
date, not by creation order or name.

## The KPI strip

Four tiles, each over the scored outcomes in scope:

- **Pass rate**: share of scored outcomes at or above the pass mark. `null`
  when nothing is scored yet, not zero.
- **Mean score**: the mean of scored percentages.
- **Score spread**: minimum, maximum, and interquartile range of scored
  percentages, linear-interpolated (`quantile`), not a simple midpoint rule.
- **Entry completeness**: scored plus absent, over every outcome including
  missing ones. This is the one tile where absence counts toward the
  numerator, since it measures whether marking happened, not how it went; a
  low value is shown as a warning, since it means grading isn't done, not
  that grading went badly.

Every tile is `null`, not zero or a dash, when its denominator is empty; a
zero on a report is a real answer to a question ("nobody passed"), and must
never be confused with "there was no question to answer yet."

## The chart cards

- **Performance levels**: a count of scored outcomes at each of EE, ME, AE,
  BE (`lib/grading.ts`'s thresholds, 80/60/40 percent of maximum), as a bar
  list.
- **Histogram**: scored percentages in ten-point bands, `0 to 9` through
  `90 to 100`. Pooled across every assessment in scope, not one histogram
  per assessment, matching `summarise`'s own behaviour: the bins describe
  the scope as a whole.
- **Trend**: one point per assessment in scope, in date order, plotting
  pass rate or mean score with a reference line at the pass mark. Two
  compared scopes align their points by subject, assessment name, and term
  together, since an `Overall` scope holds one of each subject's CAT 1, and
  two streams being compared should line up equivalent sittings, not just
  equivalent positions in a list.
- **Criterion breakdown**: average share achieved per criterion, keyed by
  criterion *name* so the same criterion combines across several
  assessments even though each assessment's grid is a separate set of rows;
  read only from grids, never from `results`, since a finalized total alone
  doesn't carry its criterion-level breakdown. Hidden entirely for an
  `Overall` scope, since criteria are specific to one subject's rubric and
  don't combine across subjects.
- **Attention list**: students whose mean percentage across the scoped
  assessments falls below the pass mark, lowest mean first, each with their
  latest result and performance level.

## Scope and filters

A scope is a stream plus a subject, or `Overall` for that stream across
every subject it offers. `Overall` normalises every figure to percentages
(already true for every figure here) and hides the criterion breakdown,
since there's no one rubric to break down. Which scopes a user may pick
from is `lib/reportScopes.ts`'s concern, not this file's: it derives from
what a teacher teaches and every stream's subject list, not from anything
analytics computes.

Filters narrow further: a term, or `Year to Date` spanning all three, and
an assessment name, or every name. Filters and scope compose the same way
regardless of who's asking; the API takes the same scope and filters
whether the request comes from Reports, a PDF job, or the assistant, so
nothing about narrowing a query is specific to any one caller.

## Decline and attention

The attention list (above) is already built: a mean below the pass mark.
Decline is a different signal, not currently computed anywhere, and this
file defines it: **a student is flagged as declining when their latest
scored percentage in scope is at least ten points below the mean of their
own preceding scored percentages in that same scope**, and they have at
least two scored outcomes to compare. One data point can't decline; it can
only be low or not.

Ten points is a constant, alongside `PASS_MARK_PCT`, until real usage
suggests otherwise. This is a trend flag, not a one-off dip: comparing the
latest result to the single assessment before it would flag ordinary
noise, a strong term followed by one merely average result, as a decline.
Comparing it to the running mean of everything before it asks whether the
student is doing worse than their own pattern, which is what "declining"
should mean.

This is computable as a window function: the mean of every prior scored
percentage for that student in scope, ordered by date, compared against
the latest one. `docs/build-plan.md` item 1.4's seeder needs at least a few
students whose planted scores actually fall this way, or the flag has
nothing to find in the demo data.

Net level movement, named in `docs/build-plan.md` item 4.1 as a use for
window functions alongside trend, is this: for each student, each
consecutive pair of scored assessments in scope, ordered by date, compares
performance level (EE, ME, AE, BE) to the one before it and classifies as
up, down, or the same. Net level movement for a scope is the count of "up"
transitions minus the count of "down" transitions, summed across every
student and every consecutive pair in scope. Unlike decline, which compares
a student's latest result to the mean of everything before it to catch a
sustained trend, this compares each step to the one immediately before it,
since "movement" is a question about the step just taken, not a pattern
over several. Both are the same shape of window function, one row looking
back at the row or rows before it, ordered by date per student.

## Where figures are computed

This is target architecture, not today's state: right now `lib/analytics.ts`
is the only implementation, because there is no API client, no sync engine,
and no server yet (AGENTS.md's current-stage note). Once item 4.2 wires the
real screens to the API, every figure in this file will be computed once,
server-side, as a SQL aggregate over `marks` and `results`, per
`docs/build-plan.md` item 4.1: "the relational store is the reason the
backend was chosen." There will be no client implementation to keep equal
to it, no shared case file, and no ongoing second source of these numbers
to drift from the first.

Reports will be an online-only screen: `GET /api/reports/summary` called on
demand. This is a named, deliberate exception to AGENTS.md's zero-connectivity
rule, not a quiet departure from it: AGENTS.md already carves out exactly
this shape of exception for the assistant, and this file widens it to the
screen the assistant lives in, for the reason given there, a report is a
retrospective view of work already recorded, never the marking grid or sync
itself. Two distinct states follow from the online-only design, not one:

- **Nothing has been fetched yet** (a fresh visit, offline from the start).
  The screen shows a plain state saying so, needs a connection, and the
  fetch retries automatically the moment connectivity returns, the same
  reactive pattern `useConnectivity`'s `onChange` already drives elsewhere
  (`lib/connectivity.ts`), not a button a teacher has to remember to press.
- **A report has already been fetched this session.** It stays on screen
  if connectivity drops afterward; nothing about losing a connection erases
  what's already rendered. What becomes unavailable is changing scope or
  filters, generating a new PDF, or anything else that needs a fresh
  request. Reconnecting doesn't silently replace the numbers a teacher is
  currently reading; it makes a refresh possible again, not automatic,
  since a report changing under someone mid-read is worse than a report
  being a little stale.

This is a real narrowing of the offline-first principle for this one
screen, and it's deliberate: a report is a retrospective view of work
already recorded, not a surface that needs to keep working while a teacher
is mid-entry with no signal, the way the marking grid does.

`lib/analytics.ts` is today's only implementation, since the API doesn't
exist yet, and every screen that reads it, `ReportsScreen`, `AssessmentReport`,
`AiDialog`, `ScopePicker`, `useReport`, retires that dependency once item
4.2 wires them to the real endpoint. Nothing in `features/dashboard` reads
this module, so the dashboard's own stat tiles are unaffected either way.

## The assistant

`AiDialog` never computes anything itself; it takes `report: AssistantReport`
as a prop, handed down from whatever the screen already holds
(`features/reports/AiDialog.tsx`). That's what lets its stand-in survive
this section's decision with no client analytics code at all: it never
needs to derive anything from `lib/analytics.ts`, only to read a
`Summary`-shaped object already sitting in the screen's own state.

The dialog itself still needs a *current* connection to open, per
AGENTS.md's rule, whether or not a report happens to be cached from
earlier in the session. This isn't the same condition as the report
staying visible: a cached report can sit on screen while offline (Where
figures are computed, above), but the dialog checks connectivity itself
and shows its own offline state whenever it's down, rather than reading
whatever's cached the moment a report exists. The reason isn't
consistency for its own sake: once the real assistant exists, every
question is its own live call to the server, so it will always need a
connection at the moment of asking, not just at some earlier fetch.
Letting the stand-in work offline off a cached report now would teach a
habit the real assistant can't keep, so it doesn't get that habit to
begin with.

The dialog's current copy, "Marking and reports keep working offline,"
becomes false the moment 4.2 lands, since reports no longer work offline
from a cold start. Item 4.2 changes it to: "The assistant runs on the
server, and needs one to answer. Marking keeps working offline; reports
and this dialog need a connection." Decided here, not deferred.

## Explicit exclusions

- **Predicted marks.** The system records what happened; a prediction shown
  next to a mark invites the mark to be entered to match it, which defeats
  the point of recording anything.
- **Causal classification**, why a student declined rather than that they
  did. The data available, marks and dates, cannot support a causal claim,
  and asserting one would be indefensible at the viva.
- **Any figure that compares timestamps across devices.** Every figure here
  is computed from scored values and dates already fixed on the server
  side of a sync (ADR 0001); nothing analytical ever needs wall-clock
  comparison, consistent with the rest of the system.

## Decisions this file makes

1. **Pass mark stays a constant, not a per-institution setting, for now.**
   Nothing in the domain today asks for two institutions to grade
   differently, and promoting it to a column on `institutions` later is
   additive, the same accepted-in-advance shape as term in
   `docs/spec/data-model.md` (#34): decide it now as simple, revisit it
   only if a real need for institution-specific pass marks shows up.
2. **The histogram stays pooled across the scope, not one bucket set per
   assessment.** This isn't a fresh decision so much as a constraint: the
   TypeScript already pools, is already tested that way, and redefining it
   here would mean changing that code in the same merge request, which is
   out of scope for this file alone.
3. **Decline is a mean-relative trend flag, defined above**, not a
   single-step drop, so ordinary noise doesn't trigger it.
4. **Every figure is computed once, server-side, honoring
   `docs/build-plan.md` item 4.1 literally, not amended around.** An
   earlier draft of this file split computation between the device and the
   server, kept equal by a shared case file; that was never a decision
   anyone made, it was a recommendation carried into the draft
   unconfirmed, and it directly contradicted 4.1's own "do not compute
   these on the client." Reports becomes online-only, matching the
   assistant's existing offline behaviour, rather than the sync layer
   gaining a second, duplicated implementation of figures that aren't its
   examined contribution.
5. **No separate analytical store, and no materialized view yet either.**
   The server-side computation this file describes is the data layer the
   assistant reads from, organised as ordinary SQL, possibly a dedicated
   reporting schema of views over the same OLTP tables, not a warehouse fed
   by replication. Marks are rewritten after the fact by conflict
   resolution and unlock, which would make a replicated copy a second
   consistency problem on top of the one this project already solves
   carefully once.

   A materialized view is a smaller version of the same problem, not a
   free performance win: even refreshed lazily, a dirty flag set on write,
   refreshed on the next read before answering rather than on a schedule,
   it still pays the same full aggregate a live view already pays, plus
   the flag, plus a refresh lock or `CONCURRENTLY` with a unique index.
   More machinery for the same number, worth it only once there's an
   actual latency problem to justify it, and there isn't a measured one:
   how fast these queries run against realistic data is unknown until
   `docs/build-plan.md` item 1.4's seeder exists and `EXPLAIN ANALYZE` can
   run against it, which is a gap, not an assumption.

   The trigger, stated now rather than left vague: materialize a specific
   report query once its measured time against the seeded school exceeds
   **250ms server-side**, refreshed lazily as above so a report is never
   stale relative to what the grid already shows. That number is a
   starting default, not a measurement; revise it once real numbers exist.

   This escalation is lower risk to defer than the term or pass-mark
   decisions above, not the same shape as them: those are schema changes
   visible to the API and the client. This one happens entirely behind the
   endpoint, invisible to both, so waiting for a real number costs nothing
   either of them would notice.

# Workflow

## The lifecycle

`scheduled → in-progress → complete → finalized → reports-generated`,
forward-only except through unlock (below).

`complete` isn't a teacher action; it's derived, the same way `entered` and
`total` already are on `frontend/src/fixtures/assessments.ts`. An assessment
is `complete` when every cell in its grid is scored or marked absent, empty
counts against it, and `in-progress` when some but not all are. Nothing sets
this field directly; it's recomputed from the grid every time it's read.

`finalized` is the one deliberate, one-way gate in the lifecycle: a teacher
action, not a description of grading progress. This is the hinge the build
plan means: everything downstream, comments and reports, waits for it, and
nothing upstream of it (`complete`, `in-progress`) triggers anything on its
own.

`reports-generated` means every student in the assessment now has an
accepted comment and a generated report. Reports arrive per student, one at
a time (Report generation, below), so an assessment sits at `finalized`
while some students are done and others aren't, and only advances to
`reports-generated` once the last one finishes.

## Finalize

A push mutation on `assessments`, `fields: {status: "finalized",
finalizedAt, finalizedBy}`, going through `POST /sync` exactly like any
other assessment edit (`docs/spec/sync-protocol.md`, #35), not a separate
endpoint. It's accepted only when:

- The acting user is the assessment's creator or a teacher assigned to that
  class and subject (`teacher_assignments`), per `docs/spec/access-model.md`
  (#36) Option B. Otherwise `forbidden`.
- No mark on this assessment has an open conflict. Otherwise `invalid`. A
  finalized total with a contested cell underneath it isn't a record a
  school can stand behind, so this is a hard rule, not a warning.

Completeness is not a precondition. An assessment with empty cells can be
finalized; the grid shows a warning, but the decision to finalize past it is
the teacher's, the same way grading itself is unrestricted.

Two teachers finalizing the same assessment at once needs no special
handling: both mutations set `status` to the identical value `"finalized"`,
so `sync-protocol.md`'s rule 4 auto-resolves it (equal values on both sides
of an overlapping field) rather than raising a conflict over an agreement.

On acceptance, the server dispatches comment generation for every student on
the roster (Comments, below) as a side effect of that one accepted write, not
a separate request the device makes afterward. On the device, the grid locks
the moment the pending `status: "finalized"` fields apply locally, the same
as any other accepted write showing up immediately, optimistic before
confirmation.

## Comments

One `comments` row per student per assessment (`docs/spec/data-model.md`,
#34), created in `draft` state when finalize is accepted, submitted in
batch to the Anthropic batch API per `docs/build-plan.md` item 4.3, never
generated synchronously on a request.

Any authenticated user may edit, accept, or regenerate a draft, not only the
teacher who finalized the assessment, consistent with grading itself being
unrestricted. Whoever accepts or edits a comment is recorded as its
`author_id`; authorship follows the action, not the finalize event.
Regenerating discards the current draft's text and requests a fresh one;
editing keeps and changes it. Accepting moves the row from `draft` to
`accepted` and is what triggers that student's report (Report generation,
below); there's no batched "accept all."

## Report generation

Triggered per student, on that student's comment being accepted, per
`docs/build-plan.md` item 4.4, not batched at the assessment level and not
waiting for the rest of the class. The job renders the PDF, writes it to
S3, and records `generatedAt` and the S3 key on that student's `reports`
row. The device downloads it through a presigned URL when online; nothing
about the PDF itself is cached ahead of a request.

When a student's report finishes and every other student on the assessment
already has one, that same job also advances the assessment's own status to
`reports-generated`. This is the only place that status is set, and it's a
consequence of the last report landing, not a separate action anyone takes.

## Unlock

An ADMIN action (#36), always online, outside the mutation path entirely
(#35): it writes an `unlock_notes` row and the assessment's status directly,
never through the outbox. A note is required only once a report exists for
at least one student on the assessment; unlocking a `finalized` assessment
that has no reports yet needs no note, since nothing downstream has
happened yet to explain unwinding. This matches `docs/build-plan.md` 0.7:
"unlock requires a resolution note only where reports already exist, and is
silent otherwise."

Unlocking clears the `finalized` (or `reports-generated`) status; nothing
about it clears any mark, so the assessment's status is simply whatever the
grid's own completeness derives to afterward, per the same rule as
everywhere else in this file: `complete` if every cell is scored or marked
absent, `in-progress` otherwise. It's `in-progress` precisely for an
assessment finalized with empty cells still in it, since finalizing was
never a precondition of completeness, and unlocking doesn't retroactively
make it one.

Every `comments` and `reports` row from the cycle being unwound is soft
deleted, not overwritten: history stays queryable, and a fresh cycle, new
draft comments, new reports, begins the next time finalize is accepted, as
if for the first time. This is what `docs/spec/access-model.md` means by
"re-finalizing the assessment retriggers comment and report generation as
if it were being finalized for the first time": that's the mechanism, a new
cycle over soft-deleted history, not the same rows reused.

## Conflicts across the hinge

Finalize's own precondition, no open conflicts, is only true if it's
actually serialized against a concurrent write, not merely checked and
trusted. Checking "no open conflicts" and then committing `finalized` are
two different moments; without a shared lock, a mutation that raises a new
conflict on one of this assessment's marks could commit in between them,
and finalize would never see it. Locking the assessment row alone doesn't
prevent this, since the conflicting write touches a mark row, not the
assessment row. Finalize's transaction therefore locks every mark row
belonging to the assessment (`SELECT ... FOR UPDATE` across them), not only
the assessment row, for the duration of its check and its commit. Any
concurrent mutation that would touch one of those marks, including one
about to raise a conflict, waits for finalize's transaction to finish
first, so the check finalize performs is against a state nothing else can
change out from under it. This is what makes "prevented by construction"
actually true, rather than an assertion resting on an unstated assumption.

The case that isn't prevented by construction, because it's a different
shape of problem, is the reverse: a mark edit that was made offline before
an assessment finalized, and only reaches the server after. This needs a
rule `sync-protocol.md` doesn't state, because it's specific to this hinge,
not a general property of the sync protocol: a mark mutation is rejected
`invalid` if its assessment's status is `finalized` or `reports-generated`
at the moment the push is processed, checked before the mark's own
`baseVersion` is even considered, since an accepted write here would
silently defeat the lock finalize is supposed to be, regardless of what
else might also be true about that mark's version. This check is against
the assessment's *current* status, not its status at some earlier point,
so an edit that arrives after the assessment has since been unlocked again
processes normally, through the ordinary rules, because the grid is
genuinely open again by then.

Rejection alone would mean the teacher's actual work simply disappears: the
mark is sitting locally, the server has said no, and nothing durable records
that this happened. That's the one place this rule would break the pattern
the rest of the system holds to, an outbox entry is never silently
abandoned, a delete is soft, history is kept. So the rejection also writes
an `edit-blocked` notification (`docs/spec/data-model.md`, #34), visible to
the teacher whose edit was rejected and to whoever is assigned to that class
and subject, naming the assessment and stating plainly that unlocking is
the path forward. Once unlocked, the teacher re-enters the mark as an
ordinary fresh edit; the original rejected mutation is not retried, per
`sync-protocol.md`'s rule that `invalid` never auto-retries.

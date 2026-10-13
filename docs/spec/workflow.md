# Workflow

## The lifecycle

`scheduled → in-progress → complete → finalized`, forward-only except through
unlock (below).

`complete` isn't a teacher action; it's derived, the same way `entered` and
`total` already are on `frontend/src/fixtures/assessments.ts`. An assessment
is `complete` when every cell in its grid is scored or marked absent, empty
counts against it, and `in-progress` when some but not all are. Nothing sets
this field directly; it's recomputed from the grid every time it's read.

`finalized` is the one deliberate, one-way gate in the lifecycle: a teacher
action, not a description of grading progress. It locks the assessment's marks
and writes its results (Finalize, below). Nothing upstream of it (`complete`,
`in-progress`) triggers anything on its own.

Comments and report cards are not part of an assessment's lifecycle. They belong
to a student's term and wait for the term close (below), per ADR 0012.

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

On acceptance, in the same transaction, the server writes a `results` row for
every student who has at least one scored mark on the assessment: `total`, `max`,
and `level`, computed with the same level thresholds the analytics service uses,
never a second copy of the rule. A student marked absent in every cell gets no
result: absence is an explicit mark kind, not a null total. Finalize dispatches
nothing else; comments wait for the term close. On the device, the grid locks the
moment the pending `status: "finalized"` fields apply locally, the same as any
other accepted write showing up immediately, optimistic before confirmation.

## Term close

The act that makes report cards possible, and the trigger for 4.3. It is an
online-only action on one stream's term, like unlock, outside the mutation path
entirely: it needs a cross-assessment check and has server-side effects, and it
happens once a term, so the offline-first cost of requiring a connection is
negligible. It is accepted only when:

- The acting user is a teacher assigned to any subject of the stream
  (`teacher_assignments`), or an admin. Otherwise `forbidden`. Being the class
  teacher is not a capability (`docs/spec/access-model.md`).
- The stream has at least one assessment in that year and term, and every one is
  `finalized`. Otherwise `invalid`, naming the assessments still open. A card built
  on a term with an unlocked assessment would show figures that can still move.
  The check runs under the same lock as finalize: the transaction locks the
  stream's assessment rows for that year and term (`SELECT ... FOR UPDATE`) and
  rechecks under the lock, so an unlock or finalize that commits between the check
  and the closure cannot leave an open assessment inside a closed term.

On acceptance, in one transaction, the server writes a `term_closures` row
(`docs/spec/data-model.md`), creates one `comments` row per student enrolled in
the stream that year, in state `pending`, and dispatches generation after commit
(ADR 0007). A student who already has a live comment for that term, because a
stream they were in earlier closed it first, is skipped, since a comment is one
per student per term; the response counts the skipped students. Closing a term
that is already closed is the no-op.

It is `POST /api/term-closures` with `class_id`, `year`, and `term`: `201` with the
closure when this request closed the term, `200` with the existing closure when it
was already closed, `403` for a user who may not close it, and `422` when the
term cannot be closed yet.

While a term is closed, creating an assessment for that stream, year, and term is
`invalid`: its marks would not be on the cards already issued. So is an edit that
would move an assessment into a closed term or out of one; a rename is not refused,
since the name is not on the card. The way back is an
admin's unlock of any assessment of that term, which unwinds the close (Unlock,
below), after which the term is closed again once the new assessment is finalized.

## Comments

One `comments` row per student per term (`docs/spec/data-model.md`), the remark on
that student's report card, never one per assessment (ADR 0012). It is created
`pending` by the term close and submitted in batch to the Anthropic batch API per
`docs/build-plan.md` item 4.3, never generated synchronously on a request.

States: `pending` (generation is in flight), `draft` (text is there, a human has
not accepted it), `accepted`, and `failed` (generation errored or expired; the
teacher writes their own). `author_id` is null until a person acts: a generated
draft has no author, and whoever edits or accepts it is recorded as its author.
Authorship follows the action, not the term close.

**What the model is sent.** Never a name, an admission number, the school, a
percentage, or a class position. The prompt carries the subject, the term, the
performance level for each subject, and the trend against earlier terms,
and refers to "the student". The name is put back on our side when the card is
rendered. This is the first boundary to revisit if the privacy position changes,
and it lives in one place, a prompt builder. Without an API key (development, CI),
drafts are templated from the same figures and labelled as stand-ins, as the
assistant's are: the body starts with `[Stand-in]`, so the label travels with the
text wherever it appears.

Any authenticated user may edit, accept, or regenerate a comment that is not yet
accepted, not only a particular teacher, consistent with grading itself being
unrestricted. These are commands, not field patches, so that a stale `accepted`
can never merge over a body the acceptor did not see. Editing keeps and changes the
text and supersedes any generation in flight; it is allowed on a `failed` comment
too. Regenerating discards the text and requests a new one. Accepting moves the
row to `accepted` and is what triggers that student's report card (below); there is
no batched "accept all".

An accepted comment is final until unlock. It cannot be edited or regenerated,
which keeps the card a closed record of what was issued and keeps one comment from
silently forcing a re-render of a PDF that may already be with a family.

A generation result is applied only if it is the comment's latest generation, the
comment is live, and its state is still `pending`. That one rule covers a
regenerate while a batch is in flight, an edit that overtakes generation, an unlock
mid-batch, and a duplicate submit after a crash. A failed or expired generation
sets the comment `failed` and writes a `comments-failed` notification to the user
who closed the term, once per term close: a second failure while that notice is
unread adds nothing, and one after it is read writes a new notice. It names the
stream and term, never a student, and is not sent to a deactivated user.

## Report cards

Per student per term (ADR 0012), triggered on that student's comment being
accepted, not batched and not waiting for the rest of the class. The job renders
the PDF with Dompdf, writes it to S3, and records `generated_at` and the S3 key on
that student's `reports` row. The device downloads it through a presigned URL when
online; nothing about the PDF is cached ahead of a request.

The card shows, for each subject, the performance level for Terms 1, 2, and
3 of the year so far, a term not yet closed being blank. A term's level is the level of
the subject's mean for that term, worked out from the mean percentage, which is not
shown. That mean is of the term's assessment percentages (each result's total over
its max), so every sitting weighs the same whatever its maximum; a sitting the student
missed entirely has no result and does not count. The comment prompt reads the same
levels, so the remark and the card cannot disagree. The card also carries a compact history of earlier
years drawn from the student's enrolments, the teacher's remark, and the
school and student identifiers. It never shows a percentage, a class position, a
rank, or a class size. Position and percentages remain on the teacher's screens only.

A report job carries the identity of the comment it was queued for and checks it
at its final write: the comment must still be live and `accepted`, and the term
closure for that stream and term must still be live. A job that finds either
missing, because an unlock unwound the cycle after the comment was accepted, writes
nothing and ends. Otherwise a stale job could produce a report for a cycle that no
longer exists, beside the new cycle's own.

When the last student of the stream's term has a report, the server writes a
`report-ready` notification to the user who closed the term. A card is cumulative: a later
term's card is a new row, never an edit of an earlier one.

## Unlock

An ADMIN action (#36), always online, outside the mutation path entirely
(#35): it writes an `unlock_notes` row and the assessment's status directly,
never through the outbox. A note is required only once a report exists for
at least one student of the assessment's stream in its term; unlocking a
`finalized` assessment in a term that is not closed needs no note, since nothing
downstream has happened yet to explain unwinding. This matches `docs/build-plan.md` 0.7:
"unlock requires a resolution note only where reports already exist, and is
silent otherwise."

Unlocking clears the `finalized` status and soft deletes that assessment's `results`; nothing
about it clears any mark, so the assessment's status is simply whatever the
grid's own completeness derives to afterward, per the same rule as
everywhere else in this file: `complete` if every cell is scored or marked
absent, `in-progress` otherwise. It's `in-progress` precisely for an
assessment finalized with empty cells still in it, since finalizing was
never a precondition of completeness, and unlocking doesn't retroactively
make it one.

If the assessment's term is closed, unlocking unwinds the close: the
`term_closures` row and every `comments` and `reports` row of that stream and term
are soft deleted, not overwritten, and any generation in flight is superseded.
History stays queryable, and a fresh cycle begins the next time the term is
closed, over soft-deleted history rather than the same rows reused. Every table
in the cycle (`results`, `term_closures`, `comments`, `reports`) allows one live row
per identity, enforced by a partial unique index where `deleted_at` is null, so the
new cycle inserts new rows with new ids beside the retained history. This is what
`docs/spec/access-model.md` means by "re-finalizing the assessment retriggers
comment and report generation as if it were being finalized for the first time":
the term must be closed again.

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
`invalid` if its assessment's status is `finalized`
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

# Data model

## Conventions every table shares

Every table carries `institution_id`, enforced by the Eloquent global scope
described in `AGENTS.md`, never by a query condition repeated per controller.
`institutions` is the one exception, and it's not a carved-out special case:
the scope's job is to make institution B's rows invisible to institution A's
queries, and `institutions` has exactly one row per institution. There is no
other institution's row on this table for a scope to hide; the rule has
nothing to enforce here because the thing it protects against, one tenant
reading another's data, can't occur on the tenant table itself.

A table is synchronisable if a device ever needs its rows: that covers
everything below except `institutions` itself, `unlock_notes`,
`comment_generations`, and the server-only log tables `sync_changes` and
`sync_mutations`. A
synchronisable table carries a UUID primary key, an integer `version`
incremented server-side, and `deleted_at` for a soft delete, per ADR 0001.
`created_at` and `updated_at` are informational only and are never compared to
detect a conflict or order a pull.

Synchronisable is not the same as writable from a device. Marks, assessments,
conflicts, report-card comments, and a notification's `unread` flag are the only
things a device pushes (`docs/spec/sync-protocol.md`, #35); conflicts and comments
only as commands, never as field patches. Classes, subjects, criteria,
students, and users reach a device only by pull; the device reads them but
never proposes a change to them.

Every primary key is a UUID, never an auto-increment integer, on every table
without exception. No id is ever assigned passively by the API receiving a
request: whatever is creating a row generates its UUID first, and the row is
persisted with an id it already has. For a device creating a mark or an
assessment offline, that is a client-generated UUID, exactly as AGENTS.md
requires, and the API accepts it as given. For a row with no offline device
behind it, a seeded student, a teacher account an admin creates through a
panel, the same discipline holds: the seeder or the admin action generates
the UUID (UUIDv7, so it sorts by creation time) before the row is inserted,
not the database, and not the API reacting to a request by deciding an id on
the creator's behalf. What differs between the two cases is only who is doing
the generating; the API itself never assigns an id, for any table, and never
overrides one it's given.

## Tables

The brief for this file counted thirteen tables from a reading of
`docs/build-plan.md` that predates `docs/spec/access-model.md` (#36). Two
tables that file requires, a teacher-to-class-subject assignment and a
subject-moderation grant, aren't in that count, and one table the brief
assumed (a `role` enum sitting on `users`) isn't needed once access is
layered capabilities rather than a role string. The count below is whatever
falls out of that file; forcing it back to thirteen would mean leaving out
something #36 requires.

- **`institutions`**: `id` (UUIDv7), `name`. The tenant boundary itself; not
  scoped by `institution_id` and not synchronisable, since a device belongs to
  exactly one and never needs another's row.
- **`users`**: `id`, `institution_id`, `name`, `email`, credentials, `kind`
  (`teacher` or `admin`, exclusive, ADR 0014), `directs_studies` (boolean,
  teachers only, enforced by a check constraint), `deactivated_at` (nullable).
  Email is unique per kind, `unique(kind, email)`, so one person's teacher and
  admin accounts may share an address. Always server- or admin-created, never
  offline; within a teacher account, `directs_studies` and the moderation grants
  below are the only capabilities, layered rather than exclusive. Deactivation is
  deliberately not `deleted_at`: a deactivated account still has to resolve to
  a name wherever it's referenced (a mark's `last_edited_by`, a conflict side,
  a resolution), so it stays synced and visible; it just can't authenticate or
  act.
- **`subjects`**: `id`, `institution_id`, `name`. A table, not the frontend's
  fixed three-item list (`English`, `Maths`, `Science`), because `criteria`
  and the moderation grants both reference it, and a school's subject list is
  exactly the kind of thing an institution should be able to grow.
- **`criteria`**: `id`, `institution_id`, `subject_id`, `name`, `max_score`.
  One row per line of a subject's rubric (`frontend/src/fixtures/rubrics.ts`).
- **`classes`**: `id`, `institution_id`, `grade`, `stream`, `class_teacher_id`
  (nullable, references `users`). `grade` is a column, per your answer;
  `lib/reportScopes.ts`'s `gradeOf()`, which currently derives it from the
  stream name, reads this column instead. `class_teacher_id` is the
  `homeStream` fact from `fixtures/teachers.ts`, turned around: one class has
  at most one class teacher, rather than one teacher naming a home stream.
  It's attention only, per #36, never a capability.
- **`class_subjects`**: `id`, `institution_id`, `class_id`, `subject_id`,
  unique on `(class_id, subject_id)`. Which subjects a class offers;
  `CreateAssessmentDialog.tsx`'s class picker already filters on this.
- **`teacher_assignments`**: `id`, `institution_id`, `user_id`, `class_id`,
  `subject_id`. Replaces the frontend's `subjectsByStream` JSON with a real
  table, because #36 needs to query it: it's exactly the fact that scopes
  assessment lifecycle actions (edit, finalize) to a teacher who teaches that
  class and subject. Unlocking is not among them: it belongs to a Director of
  Studies or the subject's HOD (ADR 0014, decision 3). It never gates grading or assessment
  creation, which stay unrestricted.
- **`subject_moderations`**: `id`, `institution_id`, `user_id`, `subject_id`.
  One row per subject a user moderates. "Subject head" and "head of
  department" are titles over the same row shape (#36); a head of department
  moderating three subjects holds three rows.
- **`students`**: `id`, `institution_id`, `name`, `gender`, `dob`. No
  `class_id`: which class a student sits in is a fact of a year, not of the
  student, and belongs on `enrolments`.
- **`enrolments`**: `id`, `institution_id`, `student_id`, `class_id`, `year`.
  One row per student per year; `rosterFor(classId)` becomes a query against
  this table filtered to the current year, not a column read on `students`.
  Chosen over a plain `class_id` for the years this school runs past a single
  cohort: a student's class in a prior year stays answerable without
  reconstructing it from marks. A unique constraint on `(student_id, year)`
  assumes one class per student per year; a genuine mid-year transfer isn't
  modelled and would need its own decision if it comes up.
- **`assessments`**: `id`, `institution_id`, `class_id`, `subject_id`, `name`,
  `term`, `year`, `date`, `status`, `created_by`, `finalized_at`,
  `finalized_by`. One row is one sitting of one subject in one stream, per
  your answer; `status` stores `scheduled` and `finalized`. The lifecycle
  `scheduled → in-progress → complete → finalized` includes two derived states, `in-progress` and `complete`, computed from the
  grid's completeness each time it is read and never stored (`docs/spec/workflow.md`); it is
  forward-only except through `unlock_notes`.
  `created_by` is new against the brief: #36's Option B scopes lifecycle
  actions to the creator or an assigned teacher, and there was nothing to
  check the creator against.
- **`marks`**: `id`, `institution_id`, `assessment_id`, `student_id`,
  `criterion_id`, `mark_kind` (`empty`, `score`, `absent`), `score` (nullable
  integer, checked non-null exactly when `mark_kind = score` and at most the
  criterion's `max_score`), `last_edited_by`. `id` is a deterministic UUIDv5 of
  `(assessment_id, student_id, criterion_id)` under a fixed project namespace,
  computed identically on the device and the server (see Grains and identity),
  so the primary key itself is the uniqueness constraint on that triple.
- **`conflicts`**: `id`, `institution_id`, `mark_id`, `base_version`, `mark_version` (the mark's
  version when the conflict was raised, which is the version side A produced; recorded on the
  conflict itself because a follow-up conflict, raised by a resolution command, has no mutation
  of its own to read it from; server-only, hidden from the change log and every pull), `side_a`,
  `side_b` (each `{editId, userId, who, markKind, score, at, receivedAt}`; on side A `who`, `at`,
  and `receivedAt` may be null, on side B only `at` may be; `base_version` is the version side B
  was edited against), `proposals`
  (ordered array of `{byId, by, choice, note, at, receivedAt}`), `referral` (nullable:
  `{reason: "party", byId, by, at, receivedAt}` or `{reason: "rounds", at, receivedAt}`),
  `resolution` (nullable `{kind, ...}` per ADR 0002; a human resolution (`self`, `agreed`,
  `moderated`) also carries the display names and `at`, and an `auto` resolution is exactly
  `{kind: "auto"}`),
  `resolved_at` (the server clock when it was settled). The nested fields are
  stored as JSON columns rather than normalised into child tables, matching
  the shapes ADR 0002 and `fixtures/conflicts.ts` already define; a conflict
  is read and written as one document, never queried by the contents of one
  proposal.
- **`results`**: `id`, `institution_id`, `assessment_id`, `student_id`,
  `total`, `max`, `level`. One row per student per assessment, matching
  `fixtures/results.ts`'s `ResultRecord` exactly; this grain isn't open.
  Written by the server when an assessment finalizes, only for a student with
  at least one scored mark: a student absent in every cell has no row, since
  absence is an explicit mark kind and `total` is never null. `total` is the sum
  of the scores; `max` is the rubric maximum less the criteria marked absent for
  that student, so absence stays out of the denominator, while an empty or
  unentered cell still counts against it, since finalizing past gaps is the
  teacher's decision. Only live marks on live criteria count. Soft deleted when
  the assessment is unlocked.
- **`comments`**: `id`, `institution_id`, `student_id`, `year`, `term`,
  `term_closure_id` (the close that created it), `author_id` (nullable until a
  person edits or accepts), `body` (nullable until generated; required once
  `draft` or `accepted`), `state` (`pending`, `draft`, `accepted`, `failed`). One per
  student per term, the remark on that student's report card, created `pending`
  by the term close (`docs/spec/workflow.md`, ADR 0012). `term_closure_id` is how
  an unlock finds exactly that close's comments, including a student whose
  enrolment has since moved to another stream. It has no
  `assessment_id`: a comment is about a term, not a test. Pull-only for the
  fields a device reads; edit, accept, and regenerate are commands
  (`docs/spec/sync-protocol.md`).
- **`comment_generations`**: `id`, `institution_id`, `comment_id`, `batch_id`
  (nullable until submitted), `status` (`submitted`, `succeeded`, `errored`,
  `expired`, `superseded`), `error`, timestamps. Server bookkeeping for one
  request to the batch API, with `id` as the request's `custom_id`. Not
  synchronisable, like `unlock_notes`: its own writes (submitting, polling) never
  touch a comment. Applying a result is a separate update of the `comments` row,
  under the one rule in `docs/spec/workflow.md`, through the ordinary `Syncable`
  save: it bumps `version` and logs a `sync_changes` entry, which is how a device
  sees `pending` become `draft` or `failed`.
- **`terms`**: `id`, `institution_id`, `year`, `term` (1 to 3), `opened_at`,
  `opened_by`, `ended_at`, `ended_by`, all four nullable. The term calendar an
  admin keeps (ADR 0014, decision 4): a row is opened when the school has
  opened, and ended when the next term opens or the school closes for the
  holidays. No planned dates. At most one open term per institution, and one
  live row per `(institution_id, year, term)`, both through partial unique
  indexes; a term opened by mistake is withdrawn by a soft delete. Terms that
  existed before the calendar, backfilled from the assessments that name them,
  have no `opened_at`. Synchronisable and pull-only. The rules are in the
  `Term` model.
- **`term_closures`**: `id`, `institution_id`, `class_id`, `year`, `term`,
  `closed_by`, `closed_at`. One live row per stream per term, written by the term
  close (`docs/spec/workflow.md`), soft deleted by an unlock. Synchronisable and
  pull-only, so a device knows a term is closed and can show the review screen.
- **`reports`**: `id`, `institution_id`, `student_id`, `year`, `term`,
  `generated_at`, `s3_key`. One per student per term, the report card. A later
  term's card is a new row, never an edit of an earlier one (ADR 0012). The PDF
  shows performance levels only: never a percentage, a class position,
  a rank, or a class size.
- **`notifications`**: `id`, `institution_id`, `user_id`, `assessment_id` (nullable: the
  assessment a notice concerns, for a link and so a recipient gets one unread notice per kind and
  assessment, not one per cell; it reaches a device as `assessmentId`),
  `term_closure_id` (nullable: the term close a `comments-failed` or `report-ready`
  notice concerns, so a recipient gets one unread notice per kind and term close, and
  the notice can link to it; it reaches a device as `termClosureId`), `kind`
  (`sync-conflict`, `submission`, `report-ready`, `enrolment`,
  `edit-blocked`, `comments-failed`), `tone`, `title`, `body`, `unread` (boolean). Written by
  the server (a conflict raised, a report ready), read by a device by pull;
  `unread` is the one field a device pushes back, matching AGENTS.md's
  frontend rule that notifications are synced job-status rows, not push
  infrastructure. `edit-blocked` is a later, additive value
  (`docs/spec/workflow.md`, #37): a mark edit rejected because its
  assessment was finalized before the edit arrived isn't the same fact as
  a `sync-conflict` between two people's values, so it gets its own kind
  rather than stretching that one to cover it.
- **`unlock_notes`**: `id`, `institution_id`, `assessment_id`, `user_id`,
  `note`, `created_at`. An append-only audit log, not synchronisable in the
  full sense: it's written once by the unlock call, online, with `user_id` the
  Director of Studies or HOD who unlocked. It stays on the server as the record
  of who unlocked and why; no device pulls it, and no screen shows it yet.
- **`sync_changes`**: `seq` (bigserial primary key, the pull cursor),
  `institution_id`, `table`, `record_id`, `version`, `fields` (jsonb, changed
  fields only, `deleted_at` included), `received_at` (server clock, set on every
  row, audit only; `docs/spec/sync-protocol.md`, Audit timestamps). Append-only,
  written by `Syncable` on every create, update, and soft delete inside the
  writing transaction, serialised so `seq` order equals commit order (ADR 0010).
  Not itself synchronisable and never on either verb as a table. Indexed on
  `(institution_id, seq)` for pull, and on `(institution_id, table, record_id,
  version)` for the per-record history `POST /sync` rule 4 reads, so a stale-write
  check does not scan the log as it grows. The one deliberate exception to client-generated
  UUID keys: the rule exists because records are created offline, and a log row
  is only ever written by the server, whose sequence is the cursor itself.
- **`sync_mutations`**: the record of every decided push entry, backing rule 1 of
  `POST /sync` (`docs/spec/sync-protocol.md`). `id` (the client's mutation id, the
  primary key), `institution_id`, `user_id`, `table`, `record_id`, `status`
  (`accepted`, `merged`, `conflict`, `invalid`, `forbidden`), `version` (the
  record's version in the outcome; null only for `invalid` and `forbidden`, and for a `conflict` the
  record's version at the moment it was decided), `conflict_id` (marks
  only), `change_seq` (the `sync_changes` row the entry wrote: unique, only on an accepted or
  merged outcome, null when the entry wrote nothing; how a conflict finds the write that
  produced a record's current version), `payload_hash` (of table, record id, `baseVersion`, and `fields`),
  `reason` (for `invalid`), `at` (the device's claim, informational),
  `received_at` (server clock). Append-only: no version, no soft delete, not
  synchronisable, never on either verb. The id is a client UUID like every key.
  Indexed on `(table, record_id, version)`, from before `change_seq`: it is no longer
  how the producer of a version is found (that is `change_seq`), and is a candidate for
  removal. The index rule 4's history read uses is on `sync_changes`.

## Grains and identity

An assessment is one sitting of one subject in one stream: one row per
`(class_id, subject_id, name, term, year)` in practice, though nothing
enforces that as a database constraint, since two sittings of the same name
are a school's mistake to avoid, not the schema's to prevent.

A mark's identity is the triple `(assessment_id, student_id, criterion_id)`,
and its id is a deterministic UUIDv5 of that triple, not a fresh UUID per
creation. Two devices that independently create a mark for the same cell,
each while offline, compute the same id, so the second device's create
collides with the first as an ordinary stale-version conflict (ADR 0001) the
moment they both reach the server, rather than producing two rows with two
different ids for one cell. The namespace UUID used for the v5 derivation is
fixed once and lives alongside the migration that creates `marks`.

A result's identity is `(assessment_id, student_id)`, one live row, written by the
server at finalize. After an unlock the old row stays soft deleted and the next
finalize creates a new live row with a new id, recomputed from the marks as they
then stand; a row is never restored. A comment and a report share a different identity,
`(student_id, year, term)`, for the same reason: one live row per student per term,
whichever term that is. A `term_closures` row's identity is `(class_id, year, term)`.
For all four tables the identity applies to live rows only, through a partial unique
index where `deleted_at` is null, so a cycle recreated after an unlock coexists with
the soft-deleted rows of the cycle it replaces.

An enrolment's identity is `(student_id, year)`: which class a student sat in
for a given year, not an ongoing fact about the student.

## Pre-migration decisions

1. **The id is generated by whoever creates the row; the API never assigns
   one.** Rejected: `HasUuids` with UUIDv7 everywhere, which would mean the
   API either regenerates a client-supplied id (breaking the device's own
   reference to the record it just created) or refuses client ids outright
   (breaking offline creation entirely). A seeder or an admin action
   generates its own UUID the same way a device does; the API is never the
   one deciding an id on the creator's behalf, for any table.
2. **Marks are identified by a deterministic UUIDv5, not a random UUID.**
   Rejected: a random id per creation, which turns two devices creating the
   same cell offline into two permanent rows instead of one detectable
   conflict.
3. **Term is a value (`term` smallint 1 to 3, plus `year`) on every row that
   names one, and the calendar is its own table.** Promoted by ADR 0014, as
   this decision accepted in advance: an admin opens and ends terms in the
   `terms` table, which records what happened rather than planned dates.
   Assessments, comments, reports, and closures still carry `year` and `term`
   as values rather than a key into `terms`, so nothing written before the
   calendar existed has to be rewritten.
4. **Grade is a column on `classes`**, not derived from the stream name.
   Rejected: deriving it in `lib/reportScopes.ts` as today, which works only
   because every stream name happens to encode its grade as a convention, not
   a rule the schema enforces.
5. **Teacher-to-class-subject assignment is a real table**, not the frontend's
   `subjectsByStream` JSON. Rejected: keeping it as JSON on `users`, which
   #36's lifecycle scoping needs to query (who teaches this class and
   subject), and a JSON blob isn't queryable without pulling every user's
   record and filtering in application code.
6. **A deactivated user is a flag, not a soft delete.** Rejected: reusing
   `deleted_at` for deactivation, which would make a deactivated teacher's
   name disappear from every mark and conflict that references them, when
   what's wanted is exactly the opposite: they stay visible, they just can't
   act.
7. **A student's class is a year-scoped enrolment row, not a column on
   `students`.** Rejected: a plain `class_id`, chosen for its simplicity
   originally but reversed once the years this school runs past a single
   cohort mattered; the enrolment table is the more future-proof of the two,
   at the cost of one join wherever a roster is built.
8. **Reports and comments are per student per term, not per assessment.**
   Reversed from the original per-assessment grain by ADR 0012: a Kenyan report
   card is one document per student per term, and a card per test is a stack of
   sheets nobody issues. The class-level view of one assessment is the Reports
   screen's job.


## Not modelled yet

- **A `departments` table.** #36 decided moderation is a flat list of
  per-subject grants; nothing groups subjects into departments, and nothing
  should derive one person's moderation from another subject's membership in
  some larger unit.
- **Mid-year class transfers.** `enrolments` assumes one class per student per
  year; a transfer mid-year isn't modelled and would need its own decision.
- **Predicted marks and causal classification.** Out of scope per
  `docs/spec/analytics.md` (#38); nothing here should make room for them.

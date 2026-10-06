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
everything below except `institutions` itself and `unlock_notes`. A
synchronisable table carries a UUID primary key, an integer `version`
incremented server-side, and `deleted_at` for a soft delete, per ADR 0001.
`created_at` and `updated_at` are informational only and are never compared to
detect a conflict or order a pull.

Synchronisable is not the same as writable from a device. Marks, assessments,
conflicts, and a notification's `unread` flag are the only fields a device
pushes (`docs/spec/sync-protocol.md`, #35). Classes, subjects, criteria,
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
- **`users`**: `id`, `institution_id`, `name`, `email`, credentials, `is_admin`
  (boolean), `deactivated_at` (nullable). Always server- or admin-created,
  never offline; `is_admin` and the moderation grants below are the only
  capabilities, per #36's "layered, not exclusive" decision. Deactivation is
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
  assessment lifecycle actions (edit, finalize, unlock) to a teacher who
  teaches that class and subject. It never gates grading or assessment
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
  your answer; `status` is `scheduled → in-progress → complete → finalized →
  reports-generated`, forward-only except through `unlock_notes`.
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
- **`conflicts`**: `id`, `institution_id`, `mark_id`, `base_version`, `side_a`,
  `side_b` (each `{editId, userId, markKind, score, at}`), `proposals`
  (ordered array of `{byId, choice, note, at}`), `referral` (nullable),
  `resolution` (nullable `{kind, ...}`), `resolved_at`. The nested fields are
  stored as JSON columns rather than normalised into child tables, matching
  the shapes ADR 0002 and `fixtures/conflicts.ts` already define; a conflict
  is read and written as one document, never queried by the contents of one
  proposal.
- **`results`**: `id`, `institution_id`, `assessment_id`, `student_id`,
  `total`, `max`, `level`. One row per student per assessment, matching
  `fixtures/results.ts`'s `ResultRecord` exactly; this grain isn't open.
  Written by the server when an assessment finalizes.
- **`comments`**: `id`, `institution_id`, `assessment_id`, `student_id`,
  `author_id`, `body`, `state` (`draft`, `accepted`). One per student per
  assessment, dispatched on finalize (`docs/spec/workflow.md`, #37 owns the
  lifecycle; this file only fixes the grain).
- **`reports`**: `id`, `institution_id`, `assessment_id`, `student_id`,
  `generated_at`, `s3_key`. One per student per assessment, matching
  `results` and `comments`. This isn't a narrower grain than a term report
  card: `fixtures/assessments.ts`'s own example (`a6`, named "End of Term",
  reaching `status: 'reports-generated'`) shows that a term report card is
  simply the report for whichever assessment happens to be named "End of
  Term," not a different kind of record.
- **`notifications`**: `id`, `institution_id`, `user_id`, `kind`
  (`sync-conflict`, `submission`, `report-ready`, `enrolment`,
  `edit-blocked`), `tone`, `title`, `body`, `unread` (boolean). Written by
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
  full sense: it's written once by ADMIN, online, and a device only ever
  reads it as part of an assessment's history.
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
  record's version in the outcome, null for a rejection), `conflict_id` (marks
  only), `payload_hash` (of table, record id, `baseVersion`, and `fields`),
  `reason` (for `invalid`), `at` (the device's claim, informational),
  `received_at` (server clock). Append-only: no version, no soft delete, not
  synchronisable, never on either verb. The id is a client UUID like every key.
  Indexed on `(table, record_id, version)`: the lookup that finds the mutation
  which produced a given version of a record, for a conflict side's `editId`. It
  is not the index rule 4's history read uses; that read is on `sync_changes`.

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

A result's identity is `(assessment_id, student_id)`, one row, written once
by the server at finalize. A report shares that identity for the same reason:
one per student per assessment, whichever assessment that is.

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
3. **Term is a value (`term` smallint 1 to 3, plus `year`), not a table, for
   now.** Nothing in the domain today needs a term's own start or end date,
   and a table would make term rows synchronisable reference data that has
   to exist on a device before an assessment for that term can be created
   offline. Promoting to a table later is accepted in advance, not merely
   possible: the day a real need for term dates shows up, moving to a table
   is additive and doesn't need a fresh decision to justify it, only the
   migration itself.
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
8. **Reports are per student per assessment, matching `results` and
   `comments`.** Rejected: a per-class-per-term grain, which would make
   "End of Term" a different kind of record instead of the ordinary
   assessment it already is in `fixtures/assessments.ts`.

## Not modelled yet

- **A `departments` table.** #36 decided moderation is a flat list of
  per-subject grants; nothing groups subjects into departments, and nothing
  should derive one person's moderation from another subject's membership in
  some larger unit.
- **A term-calendar table.** Not built now; decision 3 above already accepts
  it as the future path once a real need for term dates exists, so adding it
  later is an addition to this file, not a reopening of it.
- **Mid-year class transfers.** `enrolments` assumes one class per student per
  year; a transfer mid-year isn't modelled and would need its own decision.
- **Predicted marks and causal classification.** Out of scope per
  `docs/spec/analytics.md` (#38); nothing here should make room for them.

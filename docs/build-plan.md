# Build Plan

The sequence from the finished frontend port to hands-off, with a brief for each
work item. One item is one session and one merge request. Nothing here is a
schedule of hours; the milestones are the dates that matter and the items are
what has to exist by them.

## How to use this document

Each item below carries five fields. Paste the item into a fresh agent session
verbatim; do not paraphrase it and do not hand over more than one item at a time.

- **Read first** names the one or two files that bear on the item. Give the agent
  those paths, not the whole `docs/` tree. Sessions degrade when the context is a
  library rather than a brief.
- **Done when** is a testable condition. "It works" is not one.
- **Do not** names the specific wrong turn expected on that item. These are not
  generic cautions; each one is a mistake the conventional shape of the problem
  invites.

Two standing rules apply to every item. The agent does not write specs or
architecture decision records, because those have to be defended orally by their
author. And every item lands on its own branch behind a merge request, so the
commit history shows the build sequence rather than a single drop.

---

## Phase 0: the handover package

Written by hand, not by an agent. Until these exist the repository is not
self-sufficient as context, and every agent session starts by re-deriving
decisions that were settled months ago. This phase is also the only reason the
October 16 documentation deadline is survivable: these files are the source for
the Chapter 3 rewrite, so writing them is not overhead against the build.

**0.1 `docs/adr/0000-backend-platform.md`**
The move from Amplify Gen 1 to Laravel. Two arguments and no more: the domain is
relational and every analytics KPI is an aggregate over `marks`, and auth,
authorisation, and the three Lambdas collapse into one readable codebase instead
of four managed components that were configured rather than written. The
sync-ownership argument does not belong in this record; DataStore was optional on
the Amplify stack and a panelist who knows the platform will say so.

**0.2 `docs/adr/0006-own-sync-engine.md`**
Dropping DataStore and writing the synchronisation layer. This is where sync
ownership lives. It is the decision the distributed-systems workstream supports,
and the record should say so explicitly, because the simulation is the evidence
for rejecting wall-clock timestamps in favour of server-incremented versions.

**0.3 `docs/adr/0007-queue-driver.md`**
The database queue driver over Redis. Job volume is single digits per assessment;
an in-memory store on a single-instance deployment costs memory and an
operational component for throughput the system does not need; and a cached
analytics dashboard invalidated by writes that arrive hours late is a correctness
problem wearing a performance costume. A considered-and-rejected record reads
better at defense than the component would have.

**0.4 `docs/spec/data-model.md`**
Every table and column. The `marks` grain of one row per student per criterion per
assessment, and why that grain makes the criterion breakdown a single grouped
query and cell-level conflict resolution fall out of the schema. Why one
assessment row per stream's sitting. Why rubrics hang off `subject_id`. The six
pre-migration decisions in full.

**0.5 `docs/spec/sync-protocol.md`**
The two verbs, cursor semantics, per-mutation transaction boundaries, the
`accepted`, `merged`, and `conflict` verdicts, the client-generated mutation id
that makes a replay idempotent (the server answers a known id with the current
version and writes nothing), FIFO replay per record, and the conflict taxonomy
split into auto-resolvable and manual-review cases.

**0.6 `docs/spec/access-model.md`**
Unrestricted writes within an institution, ADMIN gating student, teacher, and
class records only, and `class_teacher` governing attention rather than
capability. This file is load-bearing. Without it an agent will build per-class
ownership, because that is the conventional shape of the problem, and it is wrong
here for a stated reason: teacher scarcity in low-resource settings makes rigid
per-class ownership a liability. One capability sits on top of unrestricted grading:
the moderator (subject head or head of department) who may resolve a conflict
between two teachers outright, per ADR 0002 rule 5. It gates conflict resolution
only, never grading.

**0.7 `docs/spec/workflow.md`**
Finalize as the hinge. Local and offline-capable finalisation, the batch comment
job it triggers, the draft-to-accepted comment lifecycle with the teacher as
author of record, report generation, and the unlock rule that requires a
resolution note only where reports already exist.

**0.8 `docs/spec/analytics.md`**
The KPI strip, the five chart cards, scope and filter behaviour, the decline flag,
and the explicit exclusions of predicted marks and causal classification with the
reasons they are out of scope.

---

## Phase 1: schema and foundations

**1.1 Migrations**

- Task: write migrations for the thirteen tables of the data model.
- Read first: `docs/spec/data-model.md`, `AGENTS.md`.
- Constraints: UUID primary keys on every table; integer `version` on every
  synchronisable table; `institution_id` on every table; `deleted_at` on every
  synchronisable table; `last_edited_by` on `marks`; `absent` as an enum value on
  `marks`, never a nullable column; `finalized_at` and `finalized_by` on
  `assessments`; a separate `unlock_notes` table. Additive only.
- Done when: `sail artisan migrate:fresh` succeeds and `migrate:rollback` returns
  the database to empty without error.
- Do not: use auto-incrementing keys anywhere, and do not represent absence as a
  null mark.

**1.2 Eloquent models**

- Task: models with relationships for all thirteen tables.
- Read first: `docs/spec/data-model.md`.
- Constraints: `HasUuids` with UUIDv7 for rows the server itself creates, a
  client-supplied id kept as given, and `marks` keyed by a deterministic UUIDv5 of
  `(assessment_id, student_id, criterion_id)` computed the same way on both sides;
  `SoftDeletes`; `version` incremented server-side on every save, never accepted
  from a client payload.
- Done when: a Pest test creates a full object graph from institution down to a
  mark and traverses it in both directions.
- Do not: allow a client-supplied `version` to be mass-assignable.

**1.3 Tenancy**

- Task: an Eloquent global scope for `institution_id` and a middleware that
  resolves the institution from the authenticated user.
- Read first: `docs/spec/access-model.md`.
- Constraints: the client never asserts its institution; it is derived from the
  token.
- Done when: a Pest test authenticates as a user of school A, queries every model,
  and sees no row belonging to school B, including through relationships.
- Do not: enforce scoping with a `where` clause in controllers. The point of the
  global scope is that leakage is structurally impossible rather than a matter of
  remembering.

**1.4 Factories and seeders**

- Task: seed one realistic school.
- Read first: `docs/spec/data-model.md`, `docs/spec/analytics.md`.
- Constraints: two grades, four streams, six subjects, rubrics with weighted
  criteria, roughly eighty students, three terms of assessments and marks, a
  realistic spread of scores, and a deliberate minority of `absent` values. Some
  students must trend downward so the decline flag has something to find.
- Done when: `sail artisan migrate:fresh --seed` produces data against which every
  KPI in the analytics spec returns a plausible non-empty result.
- Do not: seed uniformly random marks. The analytics work is untestable against
  noise.

This item is the highest-leverage one in the plan. Everything downstream is
tested against it, and a weak seeder means weeks of debugging analytics with no
ground truth.

**1.5 Authentication**

- Task: Sanctum token login, logout, and the authenticated user endpoint.
- Read first: `docs/spec/access-model.md`.
- Constraints: token-based, not session cookies; the school is baked into the
  credential.
- Done when: a Pest test logs in, calls a protected route with the token, and is
  refused without it.
- Do not: reach for Fortify or Breeze scaffolding; the frontend is a standalone
  application and needs none of the views.

**1.6 Health endpoint**

- Task: `GET /api/health`, unauthenticated, `Cache-Control: no-store`, `{ok: true}`.
- Read first: `frontend/src/lib/connectivity.ts`.
- Done when: a Pest test gets 200 without a token; the connectivity hook polls it
  and the pill flips when the probe fails behind a browser that says online.
- Do not: put it behind auth or the throttle; it is what a device asks before it has
  anything else.

**1.7 API client and sign-in**

- Task: `frontend/src/api/client.ts` (fetch with bearer token, typed JSON errors,
  same-origin `/api`, Vite dev proxy to the Sail container) and a sign-in route that
  replaces `fixtures/user.ts` with `GET /api/me`, cached locally so an offline reload
  still knows who is signed in.
- Read first: `docs/spec/access-model.md`, `frontend/src/app/routes.ts`.
- Done when: sign in, reload offline, the same user and `canModerate` are shown;
  Vitest covers the client with a mocked fetch.
- Do not: store the token in `localStorage`; the local store owns it once 3.3 lands,
  so put it in one place from the start.

**1.8 Continuous integration on merge requests**

- Task: GitHub Actions running the frontend typecheck, lint, and Vitest, and Pest
  against a Postgres service; the same commands as `make check`.
- Done when: a merge request shows the check; a failing test turns it red.
- Do not: add the deploy job here; that is 5.2 with OIDC.

**1.9 Branch ruleset on `main`**

- Task: linear history, squash merges only, no force push, conversation resolution
  required, the 1.8 check required once it exists.
- Done when: a direct push to `main` is rejected.

---

## Phase 2: resources

**2.1 Read endpoints**
Classes, streams, subjects, students, assessments, and rubrics, as JSON
resources. Done when the frontend can replace its static fixtures for these
entities. Reads are unrestricted for any authenticated user within the
institution.

**2.2 Write endpoints and policies**
Marks and assessments, unrestricted; student, teacher, and class records, gated
to ADMIN. Enforcement goes in Laravel policies, not in controller conditionals.
Done when a Pest test confirms a non-admin teacher can write any mark in the
school and cannot create a student.

**2.3 Finalize and unlock**
`finalized_at` and `finalized_by` set on the assessment; unlock requires a
resolution note in `unlock_notes` only where a report already exists, and is
silent otherwise. Lifecycle rules live as model methods and a policy.

---

## Phase 3: the synchronisation layer

This is the item most likely to overrun and it must not be last. If the schedule
slips, the comment job in Phase 4 is the safest thing to defer, having no
dependents.

**3.1 `GET /sync?since={cursor}`**

- Constraints: returns every row in the institution scope with `updated_at` after
  the cursor, soft-deleted rows included, plus a fresh cursor.
- Done when: a Pest test pulls, writes on the server, pulls again with the
  returned cursor, and receives exactly the changed rows and nothing else.
- Do not: omit soft-deleted rows. A client that missed a delete has no other way
  to learn of it.

**3.2 `POST /sync`**

- Constraints: each mutation wrapped in its own transaction, never the batch;
  matching base `version` accepts and increments; mismatched `version` with
  disjoint fields merges; mismatched `version` with a contested field returns
  `conflict` carrying both values and writes nothing.
- Done when: a Pest test submits a batch of thirty mutations of which one
  conflicts, and the other twenty-nine are persisted.
- Do not: infer conflicts from timestamps under any circumstances. Clock skew
  across teacher devices makes wall-clock comparison unsound, and this is the
  precise claim the simulation exists to demonstrate.

**3.3 Local store**
IndexedDB schema over Dexie, replacing the frontend fixtures. Dexie is a wrapper
over an awkward browser API and is acceptable; a replication library is not.

**3.4 Mutation outbox**
Append on every local write with a client-generated mutation id, record UUID, base
`version`, and changed fields only. Replay on reconnect, oldest first per record;
the server deduplicates on the mutation id, so a lost response is replayed without
raising a false conflict. Drop entries on `accepted` or `merged`, retain on
`conflict`. Done when the app can be taken offline, edited across several screens,
brought back online, and reconciles with the server without user intervention.
Do not send whole rows; field-level payloads are what make silent merging of
disjoint edits possible.

**3.5 Conflict resolution UI against real data**
Replace fixture conflicts with the real queue. The flow is ADR 0002 as the Sync
screen and the marking grid already implement it: both sides shown symmetrically
by value and author, a self-conflict resolved directly, a cross-teacher conflict
resolved by proposal with a required note, acceptance, or referral to a moderator,
who resolves with a required note. Every action is itself an outbox mutation
written against the version the teacher was shown.

---

## Phase 4: analytics and generation

**4.1 Analytics query service**
One method per KPI, each a grouped query over `marks`, scoped by institution,
with window functions for trend and net level movement. `absent` excluded from
denominators by an explicit predicate. Done when each method returns a value
matching a hand-calculated figure from the seeded data. Do not compute these on
the client or in PHP memory; the relational store is the reason the backend was
chosen.

**4.2 Dashboard wiring**
Connect the ported dashboard to those endpoints. Scope, term, and assessment
filters; overall mode hiding the criterion breakdown and normalising means to
percentages; the year selector reading from data.

**4.3 Comment generation job**
Queued job dispatched on finalize, submitting to the Anthropic batch API.
Comments land in draft state. The teacher edits, accepts, or regenerates, and
remains author of record. Do not generate comments synchronously on request; the
batch shape is deliberate, for cost and to avoid regenerating while marks are
still moving.

**4.4 Report generation job**
Queued job on comment acceptance, rendering with Dompdf, writing the object to S3,
and storing the key on the `reports` row. Client downloads through a presigned
URL.

---

## Phase 5: parallel and closing

**5.1 Distributed-systems simulation**
Standalone Python, outside the application stack. Trace generator producing
synthetic edit events with known ground truth; a naive last-writer-wins resolver
keyed on physical timestamps; a vector-clock resolver with element-wise merge and
concurrency detection; the system's own resolver from 3.2 (server-incremented
version, base-version check, disjoint-field merge, contested field reported as a
conflict), so the sweep scores the design actually shipped; a sweep across
increasing simulated clock skew; silent-loss rate and conflict-detection accuracy
scored against truth. This workstream can
proceed in parallel with any phase above and does not touch the delivery
timeline.

**5.2 Deployment**
EC2 with nginx and PHP-FPM, RDS Postgres, the queue worker on the app host,
frontend static assets to S3 and CloudFront, GitHub Actions authenticating with
OIDC and IAM roles rather than long-lived credentials, API deployment behind a
manual trigger, migrations additive only so that offline clients that have not yet
synced are never broken.

**5.3 Hardening**
Bring `make check` green across typecheck, lint, Vitest, Playwright, and Pest.
End-to-end coverage of the offline path specifically: install, go offline, edit,
come back, reconcile.

---

## Stack inventory

What the build targets. Anything not on this list is an addition, not a detail,
and needs a decision record before it enters the repository.

**Client**

- React 19, TypeScript, Vite 8, Tailwind 4
- Own router and own chart components; no routing or charting library (ADR 0003)
- Lucide icons as inline SVG paths
- Fraunces for display, Figtree for body and tabular data, both self-hosted
- Workbox via `vite-plugin-pwa` for the service worker (ADR 0004)
- IndexedDB via Dexie as the local store, with the mutation outbox above it

**API and application layer**

- Laravel 13, JSON only; no Inertia and no Blade views
- Sanctum for token authentication
- Eloquent, with a global scope for `institution_id` and middleware resolving it
  from the authenticated user
- Laravel queues on the database driver, worker on the app host (ADR 0006)
- Dompdf for report rendering, from a queued job
- Laravel Sail for the local Docker environment

**Data**

- PostgreSQL 18 on Amazon RDS, chosen over MySQL for window functions
- Amazon S3 for report PDFs, retrieved through presigned URLs

**External services**

- Anthropic Claude API, batch endpoint, called from a queued job for draft
  comments

**Infrastructure**

- Amazon EC2 running nginx and PHP-FPM
- S3 and CloudFront for frontend static assets
- GitHub Actions for CI/CD, authenticating with OIDC and IAM roles rather than
  long-lived credentials

**Repository and process**

- Monorepo: `frontend/`, `api/`, `docs/adr/`, `docs/spec/`
- Makefile as the entry point for setup and daily commands
- `AGENTS.md`, `CONTRIBUTING.md`, numbered decision records
- Branch ruleset on `main`: linear history, squash merges, no force push, merge
  requests with conversation resolution required

**Testing**

- Vitest and Playwright on the frontend, Pest on the API, all behind
  `make check`

**Parallel workstream**

- Python, standalone and outside the application stack, for the
  conflict-resolution simulation

**Considered and rejected**

- Amplify Gen 1, meaning AppSync, DynamoDB, DataStore, Cognito, and three
  Lambdas (ADR 0000, ADR 0006)
- Laravel Vapor: paid, and it reintroduces the managed opacity the platform
  change was meant to remove
- Redis for queues and cache (ADR 0006)
- Replication and CRDT libraries, meaning RxDB replication, ElectricSQL,
  PowerSync, Dexie Cloud, Automerge, and Yjs: each delegates reconciliation and
  hollows out the distributed-systems workstream
- Inertia: it requires a server response for every navigation, which fails
  entirely offline
- Jetstream teams: a competing tenancy model incompatible with the
  `institution_id` global scope

---

## Milestones

| Date | State |
|---|---|
| 21 September | Phase 0 complete. The repository is self-sufficient as context; no decision lives only in a chat transcript. |
| 2 October (alignment) | Phases 1 and 2 complete, 3.1 and 3.2 drafted. Demonstrable: log in, browse real data, enter marks against the API. |
| 16 October (documentation) | Phase 3 complete end to end. Chapter 3 rewritten from the spec files, which are by then the source rather than the summary. |
| 31 October | Phase 4 complete. Feature-complete system. |
| 15 November | Simulation finished, system deployed, `make check` green. |
| 20 November | Hands-off. The preceding five days are buffer, not work. |

The October dates are self-imposed and movable. The November one is not, and the
buffer before it is the thing most likely to be spent and most costly to lose.

---

## Standing constraints

These hold across every item and are the ones an agent is most likely to breach
while doing something otherwise reasonable.

- Schema changes are additive only, because a client that has been offline for a
  fortnight must still be able to sync.
- Conflicts are detected by version, never by timestamp.
- `institution_id` is enforced by the global scope, never by per-query
  discipline.
- Absence is an enum value excluded by explicit predicate, never a null.
- Grading capability is not scoped by class assignment.
- The synchronisation layer is the project's own implementation; supporting
  concerns may use maintained tools, but reconciliation may not be delegated to a
  replication library.

---

## Reconciliation with the repository (18 September)

Places where this document and the repository disagree because the port made
decisions after it was written. Each is resolved in the Phase 0 file named; until
then the recommendation stands.

| # | This document said | Repository | Resolution |
|---|---|---|---|
| 1 | ADR 0005 own sync engine, ADR 0006 queue driver | 0004 (Workbox) and 0005 (router) exist | Applied: 0.2 and 0.3 name 0006 and 0007; 0.1 stays 0000 |
| 2 | 3.5: three card states, optional resolution note | ADR 0002 decided proposals with required notes, referral, and a moderator; the Sync screen and grid implement it | Applied to 3.5. `sync-protocol.md` describes conflict rows carrying `proposals`, `referral`, `resolution` |
| 3 | 0.6: unrestricted writes, ADMIN gates records, `class_teacher` governs attention | ADR 0002 rule 5 needs a moderator role (subject head or head of department) | Applied to 0.6: the moderator capability gates conflict resolution only |
| 4 | 1.2: `HasUuids` with UUIDv7 | Ids are client-generated (AGENTS.md, ADR 0001 rule 5) | Applied to 1.2: whoever creates a row mints its id (a device for records created offline, the server for seeded and admin-created rows); the server never replaces a supplied id; marks use the deterministic UUIDv5 |
| 5 | `marks` grain: one row per student, criterion, and assessment | Two devices can create the same cell offline under two ids | `data-model.md` fixes cell identity: a deterministic UUIDv5 of the triple on both sides plus the unique constraint, so a double create is a version conflict, never a duplicate |
| 6 | PostgreSQL on RDS | Examiners are cool on managed services on the core | The core is the sync layer, not database hosting. RDS stays; ADR 0000's argument against managed components should not prove too much |
| 7 | No local stand-in for S3 | `compose.yaml` has none | Sail's `minio` service when 4.4 starts, and a line in the stack inventory |
| 8 | 5.2: frontend on S3 and CloudFront | The service worker assumes `/api` is same-origin | CloudFront routes `/api/*` to the EC2 origin; recorded in 5.2's brief |
| 9 | Not listed: health endpoint, API client and sign-in, CI on merge requests, branch ruleset | The connectivity hook polls a health route; two people on squash merges need CI before the first API ticket | Applied: items 1.6 to 1.9; the OIDC deploy part of GitHub Actions stays in 5.2 |

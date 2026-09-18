# Working in this repository

Instructions for any coding agent (Claude Code reads this through `CLAUDE.md`; other
tools read `AGENTS.md` directly). Humans are welcome to read it too.

## What this is

ConnectED: an offline-first examination management and academic analytics PWA for
Kenyan schools with unreliable connectivity. Final-year capstone, two developers,
completion end of November 2026.

## Stack

React and TypeScript PWA in `frontend/`, built with Vite, holding its own router and an
IndexedDB local store with a mutation outbox. Laravel JSON API in `api/`, PostgreSQL,
Sanctum token auth, Laravel queues. S3 for report PDFs. Hosted on AWS.

Not Inertia. The two halves are separate applications that share a repository. The
Laravel starter kits assume a server-rendered monolith, which is the wrong default here:
any framework feature that handles something across the client-server boundary is
probably incompatible with offline-first.

## What is ours and what is a dependency

The examination scrutinises the project's contribution: the offline synchronisation
layer. That is the local store and outbox, the version check, field-level merge, the
conflict flow under ADRs 0001 and 0002, and the server side that accepts or rejects a
write. Its logic and parameters must be our own implementation. Libraries underneath
it are fine; a managed service, sync engine, or replication framework that owns that
behaviour is not.

Everything else (service worker, containers, tooling, test runners) may use
maintained dependencies. Do not argue for hand-written infrastructure on examination
grounds; ADR 0004 reversed a hand-written service worker to Workbox for this reason.

## Non-negotiable data rules

These are load-bearing. Do not work around them.

- Primary keys are client-generated UUIDs on every table. Records are created offline
  and cannot wait for an auto-increment.
- Every synchronisable table carries an integer `version`, incremented server-side.
  Conflicts are detected by stale base version, never by comparing timestamps. Clock
  skew across teacher devices makes wall-clock comparison unsound.
- Soft deletes everywhere synchronisable. Update-against-delete is a real conflict case
  and cannot be represented if rows disappear.
- `institution_id` on every table, enforced by an Eloquent global scope, not by
  per-query discipline. Multi-tenancy is this, not Jetstream teams and not a tenancy
  package.
- Absent is an explicit enum value on a mark, never a null, and is excluded from
  analytics denominators by an explicit predicate.
- Sync mutations carry changed fields only, never whole rows. Field-level payloads are
  what allow disjoint edits to merge silently.

## Frontend rules

- The application must work with zero connectivity, which is its normal resting state.
  No remote runtime dependencies: no CDN fonts, no CDN scripts, no remote images.
- Fonts are self-hosted and precached by the service worker: Fraunces for display and
  headings only, Figtree for body and tabular data.
- Icons are `lucide-react`.
- Colour is a warm palette with a light default and a dark variant, burnt orange
  `#c1440e` as the brand accent. Colours come from the semantic CSS custom properties in
  `styles/tokens.css` through Tailwind's `@theme`. Do not introduce raw hex values in
  components.
- Connectivity and sync state are distinct facts and are displayed separately. Never
  infer one from the other.
- Dashboard cards are static: no drag or drill. The class selector on My Classes is
  the one scope control; interactive analytics live in Reports.
- Navigation depth is capped at two levels: a sidebar destination and one detail
  beneath it. Anything deeper is reached contextually, never from the sidebar: a
  student profile opens from its stream with a breadcrumb back, and that is allowed.
- User preferences are local only. Nothing that requires the network to apply.
- Notifications are records the device already holds, synced like any other table
  (job status rows: report generation, sync conflicts, enrolment changes). The feed
  works offline and needs no push infrastructure.
- The assistant lives in Reports, scoped to the report on screen, and needs a
  connection; offline it says so. Until the API's assistant exists, answers are
  templated from the report's own figures and labelled as a stand-in.
- Fixtures under `fixtures/` use short readable string ids (`s1`, `class-4w`). They
  stand in for the local store, which assigns the real client-generated UUIDs.

## Running it

`make setup` once, then `make up` for the API (Docker through Laravel Sail: `api`,
`pgsql`, and `queue` services from `api/compose.yaml`) and `make dev` for the
frontend, which runs natively on Node 22. `make check` is what must pass before a
commit. The README has the platform notes; `CONTRIBUTING.md` has the workflow.

## Conventions

The workflow (branches, merge requests, reviews, ADRs) is in `CONTRIBUTING.md`. The
rules an agent applies while writing:

- No em dashes in prose or comments; use semicolons, conjunctions, or colons.
- Oxford comma.
- "Merge request", not "pull request".
- Branches: `feat/`, `fix/`, `docs/`, `chore/` followed by a short slug.
- Squash merge into `main`. One commit per completed work item.
- `make check` must pass before any piece of work is finished: the frontend type
  check is not optional. The Figma export was never type-checked and contained
  syntax errors that esbuild silently stripped.
- Run the CodeRabbit local review (`coderabbit review --uncommitted -c AGENTS.md`) and
  address or decline its findings before every commit. Local and merge request reviews
  share one quota, so review in sweeps: one local pass per finished chunk, then let the
  bot on the merge request confirm. The bot comments; it does not block.
- Architectural decisions go in `docs/adr/` as they are made. Five exist: record
  versioning (0001), conflict resolution (0002), charts without a library (0003),
  offline with Workbox (0004), router from location (0005).

## Current stage

The frontend runs entirely on static fixtures with an in-memory session store
(`app/useSessionStore.ts`, a reducer). There is no API client, no sync engine, and no
IndexedDB store yet. The API is a fresh Laravel skeleton on Postgres in Sail, with no
domain migrations or resources. Findings that depend on a server (acknowledgements, record
versions beyond what ADR 0001 fixes, persistence across reloads, network error
handling) are future work: note them as such, not as defects to fix now. Fixture ids
are short readable strings; the store assigns real UUIDs to created records.

## How to work here

Explain before you generate. The developers must be able to defend this system at an
oral examination, and its synchronisation layer in depth, so code that works but is not
understood is worse than no code.

When a task spans more than one file or one concern, propose a decomposition and wait
for agreement rather than producing the whole thing. Prefer finishing one layer and
letting it be reviewed over carrying several layers at once.

Prefer transparent approaches over convenient ones. If a library would hide something
the team needs to understand, say so rather than reaching for it. Do not add
dependencies without asking.

Ask one focused question when a requirement is ambiguous. Do not proceed on a guess.

Architectural decisions go in `docs/adr/` as they are made.

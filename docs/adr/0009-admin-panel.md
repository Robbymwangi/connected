# 9. Admin panel as a server-rendered Filament app

Date: 2026-10-01

## Status

Accepted.

## Context

Every signed-in account, `is_admin` or not, currently sees the identical
teacher-facing PWA (`frontend/`). Nothing exists yet for the roster-management
side of `docs/spec/access-model.md`: creating or editing student, teacher, and
class records; granting or revoking a subject-moderation link; deactivating an
account; unlocking a finalized assessment with a required note. `is_admin`
today has no surface of its own to act through.

`access-model.md` already argues for what this should be, under "Tokens":

> Everything ADMIN does... is institution roster management, not a marking
> task, and has no offline requirement the way the PWA's screens do. It is a
> genuinely different piece of software from the teacher-facing app, even if
> it ends up living inside the same codebase... if it uses anything outside
> the current JSON-only stack, that choice needs its own ADR before it lands.

Discussed with the user on 2026-10-01: in scope, to land sometime before the
project is done (not blocking Phase 2 or 3), and settled as **online-only,
server-rendered, traditional web app** — not inside the PWA, not added to the
JSON API as another set of endpoints the React app consumes.

Also discussed and settled: capabilities stay layered, not exclusive, per
`access-model.md`'s existing principle. A pure-admin account (one with no
realistic teaching duties) is not technically barred from the teacher PWA;
nothing in the schema or the auth logic changes to enforce that. See
"What was considered" below for why a technical gate was rejected.

## Decision

1. **Tool: Filament, for all of ADMIN's scope, not just the easy part:
   decided 2026-10-01.** Most of what ADMIN does — student, teacher, and
   class records — is plain CRUD against Eloquent models that already exist,
   which is exactly what Filament's resource scaffolding is for, and it
   reads those models directly: `InstitutionScope` still applies with no
   bypass, because Filament's resources are ordinary Eloquent queries
   underneath, not a separate query layer to keep in sync with one. The two
   flows that look like they need hand-written screens instead turn out not
   to:
   - Granting or revoking a subject-moderation link is a `user_id`/
     `subject_id` join, which is exactly what a Filament **RelationManager**
     is for — not a form field, a managed relation on the user's resource
     page.
   - Unlocking a finalized assessment with a required note is a **custom
     Action** with its own form modal (one required text field) attached to
     the assessment resource, not a generic edit form — the kind of
     business-rule-triggering operation Filament's Actions are built around,
     not CRUD wearing a disguise.

   So the real choice was never "Filament for the 80% that's CRUD-shaped,
   Blade for the rest" — both of the non-CRUD flows have first-class
   Filament answers, which is the specific reason it fits this project,
   not a general "it's the popular choice" one.

2. **Where it lives: a Filament panel inside the existing `api/` app,
   decided 2026-10-01.** Not a new top-level directory, and not a separate
   repository (considered and rejected — see "What was considered"
   elsewhere in this file). Filament needs the same Eloquent models, the
   same migrations, and the same `User` model `api/` already has; a second
   Laravel application would mean either duplicating all of that (a second
   source of truth for the schema, the exact thing `AGENTS.md`'s tenancy and
   versioning rules exist to prevent one copy of) or extracting the domain
   models and tenancy traits (`BelongsToInstitution`, `Syncable`,
   `InstitutionScope`, `CurrentInstitution`) into a shared local Composer
   package — real setup cost, a second Sail service, a second deploy target
   — to save disk space in a `composer.lock` for code the examination does
   not scrutinise, while Phase 3, the part that is examined and the build
   plan's own "most likely to overrun" item, is still unbuilt. Separation
   from the rest of `api/` comes from structure, not a repository boundary:
   its own service provider, its own `app/Filament/` directory, and a Pest
   `arch()` rule that no API controller references a Filament class.

3. **Authentication: the same account, a separate sign-in per application.**
   One email and password per person — not two credentials to manage — but
   the PWA (bearer token, Sanctum, `POST /api/login`) and the Filament panel
   (Laravel's session guard, Filament's own login screen) are two different
   applications with two different session mechanisms, so signing into one
   does not sign you into the other.

   **The gate: decided 2026-10-01.** `FilamentUser::canAccessPanel()` returns
   `is_admin && deactivated_at === null`. No per-action policies beyond that
   inside the panel — consistent with the "no finer policies" decision
   already made for the PWA side of `is_admin`; the gate to the whole panel
   is the only check, not a separate one per resource or action. Two
   consequences of that single check:
   - Filament calls it on every request, not only at login, so a session
     already open when an account is deactivated is refused on its very next
     request — the same guarantee `EnsureAccountIsActive` gives the PWA
     (1.5), independently enforced here because this is a different guard.
   - A failed check and a wrong password produce Filament's identical
     standard failure message, so a deactivated account and a non-admin
     account trying the panel are indistinguishable from the outside, same
     reasoning as the PWA login's generic rejection (1.5).

   **Verify at build time, not decided here:** Filament pulls in
   `danharrin/livewire-rate-limiting` as a dependency; confirm the panel's
   login actually has it wired (separate from the API's own `throttle:login`,
   which this gate shares nothing with). **Still open, your call when the
   panel is actually built:** Filament ships app-based MFA
   (`pragmarx/google2fa`, already a transitive dependency) as a built-in
   option — worth it for the one surface that can deactivate accounts and
   edit the roster, or unnecessary for this project's size? Not deciding it
   now.

4. **Admin exclusivity is social, not technical.** A pure-admin account is
   simply never given a reason to sign into the PWA — nobody hands them a
   workflow that leads there — rather than the system refusing them if they
   tried. No new column, flag, or check. This keeps `access-model.md`'s
   "layered, not exclusive" principle intact rather than special-casing the
   one account type (a deputy or registrar with no classes) that the
   principle was explicitly written to allow for.

5. **Tenancy inside the panel: decided 2026-10-01.** Without this, the panel
   leaks every institution's data to every admin, silently, on a read — not
   the loud failure a bad write would give (a null `institution_id` hits the
   `NOT NULL` constraint). `InstitutionScope` applies no filter at all
   outside an HTTP request that `ResolveInstitution` has begun, and the panel
   never goes through the `api` middleware group, so without deliberate
   wiring, every Filament request looks like a trusted console run to it.
   - A dedicated `admin` guard, with a user provider that extends
     `EloquentUserProvider` and overrides `newModelQuery()` to bypass
     `InstitutionScope` for the user lookup only — the same explicit,
     narrow bypass `LoginController` already uses for the PWA's login, for
     the identical reason: there is no institution context yet to scope the
     lookup by, because finding the user is what establishes it.
   - `ResolveInstitution` is generalised to take a guard name, and the panel
     gets its own `ResolveInstitution:admin` registered as **persistent**
     Filament middleware (`->middleware([...], isPersistent: true)`), not
     ordinary route middleware. Persistence is the part most likely to be
     missed: table search, form submits, actions, and select-search inside
     the panel arrive as Livewire update requests, not fresh page loads, and
     non-persistent middleware would silently stop covering most of what an
     admin actually does after the first page load.
   - Not Filament's own `->tenant()` feature. `AGENTS.md` fixes multi-tenancy
     as the one global scope, not a tenancy package, and a second mechanism
     doing the same job would be exactly the kind of untracked parallel
     implementation that rule exists to prevent.
   - **Tests, with two institutions' data seeded:** institution A's admin can
     log in (proves the provider's bypass works at all); the list page shows
     only A's students; a Livewire table search still shows only A's (proves
     the middleware is actually persistent, not just present on first load);
     opening B's record by id 404s; creating a record fills in A's
     `institution_id` without the form ever sending it.

6. **The API stays bearer-only by config, not by accident: decided
   2026-10-01.** `docs/spec/access-model.md`, Tokens, already fixes this for
   the PWA: "No session cookies." Today that holds only because the `api`
   middleware group never starts a session, so Sanctum's guard never finds
   one to fall back to — true by the absence of a session guard anywhere in
   the app, not by any check that forbids one. The panel introduces the
   first real session guard this codebase has ever had. Once it exists,
   `config/sanctum.php`'s `'guard'` is set from `['web']` to `[]`, so
   Sanctum's own guard (`vendor/laravel/sanctum/src/Guard.php`, read closely
   during 1.5: it tries every guard named there *before* the bearer token)
   has nothing to try first, and a panel session cookie can never silently
   authenticate an `/api/*` request. This is config, not logic, so it has no
   failure mode to test for on its own — the thing worth testing is the
   scenario it prevents.
   - **Test:** `actingAs($admin, 'admin')->getJson('/api/me')` — authenticated
     against the panel's own `admin` guard (point 5), carrying no bearer
     token at all — asserts 401. `actingAs(..., 'web')` would not test this:
     the panel never uses the `web` guard, so a `web` session proves nothing
     about whether a panel session leaks into the API. Written before the
     panel's guard exists, this test fails to compile against a guard that
     is not yet registered; it starts proving something the moment 5.4 adds
     it, and it is what would catch anyone later pointing the panel at the
     `web` guard Sanctum already checks instead of its own dedicated one.

7. **Exact scope**, matched to `access-model.md`'s own exhaustive "What ADMIN
   gates" list, no more and no less:
   - Creating and editing student records.
   - Creating and editing teacher (user) accounts, including deactivating one
     (never deleting), and editing subject-moderation grants and the admin
     flag.
   - Creating and editing class records.
   - Unlocking a finalized assessment, with a required note.
   - Revoking a managed account's tokens (`access-model.md`, Tokens: "Tokens
     are revoked... by ADMIN for any account it manages"), both as a
     consequence of deactivation and as its own standalone action for a lost
     device that doesn't warrant deactivating the person.

8. **Token revocation and deactivation lockouts.**
   - A `User::booted` hook deletes the account's tokens the moment
     `deactivated_at` is set to non-null, so no panel action that saves a
     `User` model instance can forget to do it; `EnsureAccountIsActive`
     (1.5) stays as the backstop for whatever window exists before that
     hook runs, not the only line of defense. The hook fires on model
     saves only, not on a query-level bulk update (`User::where(...)->
     update(...)`), which Eloquent never routes through model events at
     all — every future deactivation path must load and save a `User`
     instance, or revoke tokens explicitly if it ever has a real reason
     to use a bulk update instead.
   - A separate "Revoke devices" action deletes an account's tokens without
     deactivating it, for a lost phone where the person still works here.
   - **Decided 2026-10-01: the panel blocks an admin from deactivating their
     own account, and blocks deactivating or demoting the last remaining
     `is_admin` account.** Without this, a mistake or a sole admin's own
     deactivation could leave an institution with no admin account at all,
     and the only recovery is the out-of-band artisan bootstrap
     (`access-model.md`, Tokens) — a console-access operation, not something
     to rely on routinely. This is a lockout guard against losing the
     capability entirely, not a finer-grained policy on who may deactivate
     whom, so it doesn't conflict with the decision in point 3 against finer
     policies.

## What was considered

**A technical lockout for pure-admin accounts** (a flag distinguishing
"admin with teaching duties" from "admin-only," checked at the PWA's sign-in)
was considered and rejected. It would contradict `access-model.md`'s own
reasoning for the layered model — a deputy who teaches, heads a department,
and administers is the normal case this schema was built around, not the
exception — and it solves a problem that does not exist: a pure admin has no
workflow that leads them to the PWA in the first place, so there is nothing
to actually lock out. Building the check anyway would be adding a boundary
this project does not need, against a threat (an admin "wandering into" the
grading app) that was never the risk the login split was protecting against.

**A separate repository for the admin panel** was considered and rejected.
Filament is not a standalone application; it mounts into an existing
Laravel app and needs that app's own Eloquent models, migrations, and
`User` model to function at all. A separate repository would have meant one
of: duplicating the domain models and migrations into a second codebase (two
sources of truth for the schema, which the rest of this project goes out of
its way to avoid — one global scope, one versioning scheme, one seeder);
publishing the first repository's models as a package the second depends on
(real operational overhead — a registry, a release process — disproportionate
to a capstone deadline); or a second app with its own, separately declared
models talking to the same database (drift risk the moment one side changes
and the other is not updated to match). None of these were judged worth it
for the benefit a separate repository actually buys: a smaller dependency
manifest in one place, for a surface with no offline requirement and no
examination scrutiny.

**Plain Blade controllers and views, hand-written, with no package** was
considered and rejected. The reason is specific to this project's shape, not
a general preference for packages: `frontend/`'s own rule against dependencies
exists because every one of its screens has to work with zero connectivity,
and a library that assumes a network or hides its own behaviour is a risk to
that guarantee (`AGENTS.md`). None of that applies here — the admin panel is
explicitly online-only, with no offline requirement at all (`access-model.md`,
Tokens) — so the argument that justifies hand-rolling the PWA's screens does
not transfer to this one. Building three resources' worth of CRUD forms,
listing, search, and sorting by hand, plus a RelationManager-equivalent for
the moderation join and a custom form flow for unlock, would mean writing and
maintaining the exact things point 1 already shows Filament has first-class,
exercised answers for — for a surface with a hard deadline and no
corresponding constraint Filament would violate.

## Consequences

- **Filament, Livewire, and Alpine become dependencies of `api/`**, not
  `frontend/` — `frontend/`'s zero-remote-dependency, offline-first rule
  (`AGENTS.md`) never applied to `api/` in the first place, and this surface
  specifically has no offline requirement, so the usual argument against
  adding a dependency does not apply here the way it would on the PWA side.
  The dry-run that confirmed Filament resolves against this project's Laravel
  13 and PHP 8.5 also flagged two security advisories in a transitive
  dependency (`league/commonmark`, both reported the day before this ADR was
  written); re-run `composer audit` when Filament is actually installed, not
  before, since a fix may already be out by then.
- **`docs/build-plan.md` gained three Phase 5 items** (5.4 guard and tenancy
  plumbing, 5.5 roster resources, 5.6 the unlock action), scheduled after 2.2
  and 2.3 since the panel reuses their policies and the unlock model method
  rather than reimplementing either.
- **`docs/spec/access-model.md` gained a short follow-up note** pointing at
  this ADR from the passage that originally left the admin surface's shape
  undecided, without editing that passage's own reasoning.
- **No change to `make check` or CI.** The panel's tests are Pest tests in
  the same `api/` suite already wired into `api-test`; nothing new to add to
  the pipeline for them to run.
- **Verify, not yet confirmed, when the panel is actually built**: whether
  customising Filament's own theme needs a build step of its own (a small
  Vite/Tailwind setup inside `api/`, separate from `frontend/`'s), or whether
  its default appearance needs no compilation step at all. Not required to
  resolve this ADR; required before 5.4 is called done.

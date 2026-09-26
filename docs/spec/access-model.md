# Access model

## The principle

Within an institution, any authenticated user may read everything: every class,
every stream, every assessment, every mark, regardless of who teaches what. Read
access is not scoped by class assignment.

Writes split into three tiers, not two.

Marks are unrestricted: any authenticated user may enter, correct, or mark absent
any student's mark for any class and any subject. The reason is teacher scarcity.
A colleague covering someone's absence, or helping another stream catch up, must
be able to enter marks without a permission change first. Rigid per-class
ownership is a liability here, not a safeguard: a school with too few teachers
cannot afford to have grading blocked on an assignment table.

Creating an assessment is likewise unrestricted, for the same reason: any
authenticated user may create an assessment for any class that offers the chosen
subject. The frontend already assumes this: `CreateAssessmentDialog.tsx` filters
the class picker by whether a class teaches the selected subject, not by who the
signed-in teacher is.

Once an assessment exists, its lifecycle is not unrestricted. Editing its name or
date, finalizing it, and unlocking it are scoped to whoever created it, or to a
teacher who teaches that class and that subject. Grading and creation are about
doing the work of examining a class; the lifecycle actions are decisions about
whether a sitting is complete and correct, and those belong to whoever is
answerable for that assessment, not to anyone with a login. ADMIN's unlock power
(below) is the one exception: it bypasses this scoping by design, because
unlocking a finalized assessment is specifically an administrative act,
independent of who ran the sitting.

## What ADMIN gates

ADMIN is a capability held by school-side staff (a deputy, a registrar, a head
teacher), not a separate technical account and not the developers. This list is
exhaustive; nothing outside it is admin-gated.

- Creating and editing student records.
- Creating and editing teacher (user) accounts, including deactivating one, and
  editing an account's subject-moderation grants and admin flag (Moderation and
  Roles, below). "Remove" means deactivate, never delete: marks and conflict
  records reference users by id, and a hard delete would orphan that history.
- Creating and editing class records.
- Unlocking a finalized assessment, with a required note. This is the only power
  ADMIN has over a mark; there is no separate power to edit a submitted mark
  directly.

A teacher account can come into existence two ways, and both stay in use: through
this admin-gated capability, for a real account added mid-year, and through the
development and demo seeder, which plants a full realistic school so the app has
something to show without an admin clicking through eighty student records by
hand. Neither replaces the other.

Once an assessment is unlocked, correcting a mark is ordinary grading through the
normal outbox path, and re-finalizing the assessment retriggers comment and
report generation as if it were being finalized for the first time.

Provisioning the very first institution and its first admin account is not part
of this capability. It is a one-time, out-of-band step (a seeder or an artisan
command), not a role and not a screen; see Tokens, below.

## Class teacher

Being the class teacher of a stream is a fact recorded for the dashboard, not a
permission. It decides which stream a teacher's own dashboard highlights by
default. It grants nothing and restricts nothing: a teacher who is not the class
teacher of a stream may still grade it, create assessments for it, and, subject
to the lifecycle scoping above, manage its assessments if they teach the relevant
subject there.

## Moderation

A conflict's resolution follows ADR 0002 exactly; this file does not change it,
only says who may act.

- When a conflict's two sides share one author, that author resolves it
  directly. No note is required.
- When the authors differ, either party may propose a resolution with a required
  note. The other party accepts it, applying it, or counter-proposes with their
  own required note. After one proposal and one counter, the original proposer
  must accept the counter or refer the conflict; there is no third round.
- A user who moderates the relevant subject may resolve a cross-teacher conflict
  outright, at any time, whether or not it has been referred, with a required
  note, and sees every open cross-teacher conflict on their moderated subjects
  from the start.

Moderation is not a gate every cross-teacher conflict passes through. It is one
path among several: most cross-teacher conflicts are expected to settle by
agreement between the two teachers, and moderation exists for the ones that do
not.

Moderation is scoped to a subject, not to the whole school: a subject head
moderates only conflicts on their own subject's assessments, matching ADR 0002's
"moderator role for the assessment." This means moderation cannot be a single
boolean on a user; it is a link between a user and the subject or subjects they
moderate.

"Subject head" and "head of department" are titles, not distinct system
capabilities. Both grant moderation through the same mechanism, one link per
subject; a head of department who oversees more than one subject simply holds
more than one link, granted individually, the same way a subject head's single
link is granted. The system has no concept of a department: nothing groups
subjects together, and nothing derives one person's moderation from another
subject's membership in some larger unit. This keeps moderation reducible to one
question, which subjects does this person moderate, rather than two.

## Roles

Capabilities are layered on an account, not chosen from one exclusive role value.
A deputy who teaches, heads a department, and administers is a normal person at a
Kenyan school, and no single enum value holds all three at once.

Every account, by default, can read everything and grade anything (The
principle, above). On top of that, an account may hold:

- Zero or more subject-moderation links (Moderation, above): one link per
  subject moderated, regardless of title.
- An admin flag (What ADMIN gates, above).

`GET /me` returns which subjects, if any, an account moderates, and whether it
holds admin, rather than a single role string. `lib/conflicts.ts`'s `abilityOf`,
and its server-side equivalent, check moderation per assessment against this
list, not against one boolean.

The exact column names and table shape are for `docs/spec/data-model.md` (#34) to
fix; this file only commits to the shape of the capabilities.

## Institution scoping

An account's institution comes from its authenticated token. The client never
asserts it, and no request parameter overrides it.

Enforcement is the Eloquent global scope described in `AGENTS.md`, applied once
per model, not a `where institution_id = ?` repeated in every controller. A row
belonging to another institution is invisible to every query an account can run;
there is no code path where it becomes visible and then gets filtered out
afterward.

The practical effect: a request naming another institution's record simply finds
nothing. A normal read returns not found. A sync push naming a record id that
resolves to nothing behaves exactly as an unknown id would (see
`docs/spec/sync-protocol.md`, #35): with a nonzero base version, that is a
forbidden verdict, not a stale-version conflict, because there is no record on
this side to be stale against.

## Tokens

Authentication is Laravel Sanctum, one token per device, bearer only. No session
cookies, and no Fortify or Breeze scaffolding; the frontend is a standalone
application with no server-rendered views to protect.

Every token carries at least a `sync` ability, which is what the PWA uses for
every endpoint. Admin-gated actions check the account's admin flag directly
rather than a separate token ability, so an admin's token behaves the same
everywhere they use it.

Tokens are revoked on logout, and by ADMIN for any account it manages.

Creating the very first institution and its first admin account is out of band:
a seeder for local development, or a one-time artisan command for a real
deployment. It is not a role, not an ability, and not a screen; whoever runs it
exists once, before the ordinary account system has anyone to provision from. No
agent should build a cross-tenant super-admin login to solve this.

Where and how ADMIN actually manages accounts (a dedicated panel, or endpoints
the PWA's admin capability calls) is not decided here. It is very likely an
online-only surface regardless of the answer, and if it uses anything outside
the current JSON-only stack, that choice needs its own ADR before it lands.

That surface is left open deliberately, not by oversight. Everything ADMIN does,
creating and editing teacher, student, and class records, granting moderation,
unlocking an assessment, is institution roster management, not a marking task,
and has no offline requirement the way the PWA's screens do. It is a genuinely
different piece of software from the teacher-facing app, even if it ends up
living inside the same codebase, and deciding its shape belongs to whichever
ticket actually builds it, not to this file.

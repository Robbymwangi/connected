# Access model

## The principle

There are two kinds of account, and they are exclusive (ADR 0014): a **teacher**
account signs in to the app and nowhere else, and an **admin** account signs in to
the admin panel and nowhere else. Everything in this section is about teacher
accounts; admin accounts are What admin accounts do, below.

Within an institution, any teacher may read everything: every class,
every stream, every assessment, every mark, regardless of who teaches what. Read
access is not scoped by class assignment.

Writes split into three tiers, not two.

Marks are unrestricted: any teacher may enter, correct, or mark absent
any student's mark for any class and any subject. The reason is teacher scarcity.
A colleague covering someone's absence, or helping another stream catch up, must
be able to enter marks without a permission change first. Rigid per-class
ownership is a liability here, not a safeguard: a school with too few teachers
cannot afford to have grading blocked on an assignment table.

Creating an assessment is likewise unrestricted, for the same reason: any
teacher may create an assessment for any class that offers the chosen
subject. The frontend already assumes this: `CreateAssessmentDialog.tsx` filters
the class picker by whether a class teaches the selected subject, not by who the
signed-in teacher is. What is not the teacher's to choose is the term: a new
assessment belongs to the term an admin has opened (ADR 0014, decision 4), and
with no term open none can be created.

Once an assessment exists, its lifecycle is not unrestricted. Editing its name or
date and finalizing it are scoped to whoever created it, or to a teacher who
teaches that class and that subject. Grading and creation are about doing the
work of examining a class; the lifecycle actions are decisions about whether a
sitting is complete and correct, and those belong to whoever is answerable for
that assessment, not to anyone with a login.

Unlocking a finalized assessment is not the sitting's to decide: it belongs to
the Director of Studies (Roles, below), independent of who ran the sitting. Until
the app has an unlock screen (ADR 0014's roles merge request), the admin panel's
unlock action is the only screen that does it, so an admin account keeps that
one academic act in the interim; the same merge request adds the HOD of the
assessment's subject and removes the panel's action.

Closing a term for a stream (`docs/spec/workflow.md`) is allowed to a Director
of Studies, and, in the interim until that same merge request, to a teacher
assigned to any subject of that stream. Being the class teacher grants nothing
here.

## What admin accounts do

An admin account belongs to the back office (a secretary, a registrar, a bursar),
not to the developers. It works in the admin panel only; the API's login refuses
it, and it can never hold a device token. A person who does both, typically a
head teacher, holds a teacher account and an admin account, with separate
passwords; email is unique per kind, so both may use one address. This list is
exhaustive; nothing outside it is admin work.

- Creating and editing student records and enrolments.
- Creating and editing class records, including who is class teacher.
- Creating and editing teacher accounts, including deactivating one, revoking its
  devices, and granting or revoking its subject-moderation links and Director of
  Studies grant (Moderation and Roles, below). An admin makes these grants and
  never holds them.
- Creating and editing admin accounts. An admin cannot deactivate themselves or
  the last active admin of the institution.
- Keeping the term calendar: opening a term when the school opens, ending the
  current term for the holidays, and withdrawing a term opened by mistake
  (ADR 0014, decision 4; the rules are in `docs/spec/data-model.md`, `terms`).
- In the interim only, unlocking a finalized assessment, with the note the
  workflow requires (The principle, above).

"Remove" means deactivate, never delete: marks and conflict records reference
users by id, and a hard delete would orphan that history. An admin account does
no academic work: it does not grade, create or finalize assessments, close terms,
or write comments, and every policy refuses it these, not only the door.

A teacher account can come into existence two ways, and both stay in use: through
the panel, for a real account added mid-year, and through the
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

The kind of account is exclusive (The principle, above). Within a teacher
account, academic capabilities are layered, not chosen from one exclusive role
value: a deputy who teaches, heads a department, and directs studies is a normal
person at a Kenyan school.

Every teacher account, by default, can read everything and grade anything (The
principle, above). On top of that, it may hold:

- Zero or more subject-moderation links (Moderation, above): one link per
  subject moderated, regardless of title. The interface will call this HOD.
- The Director of Studies grant (`users.directs_studies`): institution-wide,
  allowing unlock of any assessment and term close. A school may grant it to
  more than one teacher; it is a capability, not a seat.

Admin accounts hold neither (What admin accounts do, above).

`GET /me` returns which subjects, if any, an account moderates, and whether it
directs studies, rather than a single role string. `lib/conflicts.ts`'s `abilityOf`,
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
every endpoint. Only a teacher account ever holds one: the login refuses an admin
account, and `EnsureAccountIsActive` refuses a token belonging to one however it
was issued.

Tokens are revoked on logout, and by an admin for any teacher account.

Creating the very first institution and its first admin account is out of band:
a seeder for local development, or a one-time artisan command for a real
deployment. It is not a role, not an ability, and not a screen; whoever runs it
exists once, before the ordinary account system has anyone to provision from. No
agent should build a cross-tenant super-admin login to solve this.

Where and how ADMIN actually manages accounts (a dedicated panel, or endpoints
the PWA's admin capability calls) was deliberately left undecided by this file;
see ADR 0009, which answers it. The rule that governed that decision stays
recorded here: it is very likely an online-only surface regardless of the
answer, and if it uses anything outside the current JSON-only stack, that
choice needs its own ADR before it lands.

That surface was left open deliberately here, not by oversight. Everything
ADMIN does, creating and editing teacher, student, and class records, granting
moderation, unlocking an assessment, is institution roster management, not a
marking task, and has no offline requirement the way the PWA's screens do. It
is a genuinely different piece of software from the teacher-facing app, even if
it ends up living inside the same codebase, and deciding its shape belonged to
whichever ticket actually built it, not to this file.

**Decided, in ADR 0009**: a Filament panel inside `api/`, online-only,
authenticated by its own session guard rather than the PWA's bearer tokens, one
account and one password either way. The single gate is `is_admin` with no
finer per-action policies, checked on every request so a deactivated account's
panel session dies immediately, matching `EnsureAccountIsActive`'s guarantee on
the PWA side.

**Superseded in part, by ADR 0014**: the panel and the app are separate doors
for separate kinds of account, not one account with two sign-ins. The panel's
gate is now an active admin account (`canAccessPanel`), and the roster
endpoints the PWA once had are removed: the panel is the roster's only writer.

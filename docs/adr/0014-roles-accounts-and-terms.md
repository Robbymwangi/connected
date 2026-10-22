# 14. Back office and teaching are separate accounts; academic authority is a teacher's role; admins open terms

Date: 2026-10-13

## Status

Accepted 2026-10-13, implemented by merge requests 1 to 4 (#166, #167, #168, #170).
Decision 7 widened 2026-10-20 by #184 (below). Supersedes ADR 0009 decisions 3 (one account, two sign-ins) and 4 (admin
exclusivity is social) and narrows its decision 7 (the panel's scope). Promotes
`docs/spec/data-model.md` decision 3 (term is a value, not a table), as that decision
already accepts in advance. Changes who may close a term under ADR 0012, not what a
close does.

## Context

The rules so far were written outward from the data, not inward from how a school
runs. Building the admin panel and the term close made three mismatches visible.

**How a Kenyan school runs its academic side.** The Ministry publishes three terms a
year by circular, and schools open and close within a few days of those dates, not on
them. The back office (secretary, registrar, bursar) keeps admissions, student
records, and staff lists, and never marks. Teachers set assessments and enter marks. A
head of department (HOD) oversees a subject's teaching and marking. A Director of
Studies (DOS), or an academic deputy, owns internal examinations: the timetable,
mark-entry deadlines, results analysis, and report forms. The class teacher compiles a
class's results and writes the class teacher's remark on each report form. The
principal signs. Titles vary between schools; the functions do not.

**The mismatches.**

1. One account signs in to both the panel and the app, and `is_admin` gates academic
   acts inside the app (unlock, term close). In practice the panel is back-office
   software, and back-office staff do not unlock marks or close terms.
2. A teacher picks an assessment's term from a list. The term is not the teacher's to
   choose: the school is in Term 2 because it opened for Term 2.
3. Any authenticated user may accept a student's report-card remark, and being the
   class teacher "grants nothing". On a Kenyan report form that remark is the class
   teacher's.

## Decision

1. **Two kinds of account, exclusive.** Every user is exactly one of `teacher` or
   `admin` (`users.kind`), replacing `is_admin`.
   - A `teacher` account signs in to the app only. The panel refuses it with its
     standard failure, as for a wrong password.
   - An `admin` account signs in to the panel only. `POST /api/login` refuses it with
     the API's generic failure, and it can never hold a Sanctum token.
   - A person who does both, typically a head teacher, holds two accounts with
     separate passwords. Email is unique per kind (`unique(kind, email)`), so they may
     use one address for both; each door looks the address up within its own kind.
   - The lockout guards of ADR 0009 decision 8 stay, restated for the `admin` kind:
     an admin cannot deactivate themselves or the last active admin.

2. **Academic authority is a role a teacher account holds, exercised in the app.**
   - **HOD** is the existing subject-moderation link, renamed in the interface, not in
     the data. It keeps conflict moderation (`access-model.md`, Moderation) and gains
     unlocking finalized assessments of its own subjects.
   - **DOS** is a new institution-wide grant on a teacher account
     (`users.directs_studies`). A DOS may unlock any assessment of the institution and
     closes terms (decision 5). A school may grant it to more than one teacher; the
     grant is a capability, not a seat.
   - Admins grant and revoke both from the panel, as they already grant moderation.
     Admins hold neither and exercise neither.

3. **Unlocking moves from the panel to the app.** The unlock action leaves the panel.
   `POST /api/assessments/{id}/unlock` is allowed to the HOD of the assessment's
   subject or to a DOS, online only, with every other rule of `workflow.md`, Unlock,
   unchanged: the note required once reports exist, the unwinding of a closed term, and
   the results soft deleted. Who unlocked stays on the `unlock_notes` row.

4. **Admins open terms; a term has no planned dates.** A `terms` table (`id`,
   `institution_id`, `year`, `term` 1 to 3, `opened_at`, `opened_by`, `ended_at`,
   `ended_by`, `version`, soft deletes), synchronised pull-only like `term_closures`.
   - An admin **opens** a term in the panel: "the school has opened for Term 2, 2026".
     What is recorded is when it actually happened. Nothing predicts it.
   - At most one term is open per institution. A term opened must come after every
     term already recorded, in the order Term 1, 2, 3, then Term 1 of the next year;
     the year is what the admin names, not one read from a clock.
   - Opening the next term **ends** the current one. An admin may also end the current
     term with none to follow, for the holidays.
   - A term opened by mistake may be withdrawn while it has no assessments, which
     reopens the term it ended. Nothing else reopens a term.
   - **What an open term governs is the creation of assessments, nothing more.** A new
     assessment takes the open term's year and term; the teacher does not choose them.
     The server accepts a new assessment only for the term open when it arrives, and
     answers `invalid` otherwise. With no term open, the app offers no way to create
     an assessment and says why.
   - **What it does not govern:** an ended term's existing assessments can still be
     marked, finalized, and unlocked, so marking can run into closing week and past it.
     What ends a term's academic work is the term close (decision 5), not the next
     term opening.
   - **The offline case, accepted:** a device offline when a term opens still holds
     the previous term as open, and an assessment it creates for that term is refused
     when it syncs, with the ordinary failure notice naming the reason. The marks
     already queued against it fail with it. This is rare (a new term's first day,
     before any sync) and loud, and the alternative, accepting assessments into an
     ended term, would let a closed term's report cards go stale.

5. **A DOS closes a term, per stream.** `TermClosurePolicy` allows a DOS only; a
   teacher assigned to the stream and an admin no longer may. Every precondition in
   `workflow.md`, Term close, stays, and one is added on the creation side: once a
   stream's term is closed, the server refuses a new assessment for that stream and
   term, so a card cannot be overtaken by a sitting it never saw. A close is allowed
   while its term is still open, because report forms go home on closing day, before
   the next term opens.

6. **The class teacher owns the report-card remark.** Comment commands (edit, accept,
   regenerate) are allowed to the stream's class teacher (`classes.class_teacher_id`)
   and to a DOS, unconditionally; any other teacher is refused with `forbidden`. The DOS
   exists in this rule for the class with no class teacher and the class teacher who is
   away, but the server checks only the grant, not an absence: an "away" state is one
   nobody would keep current. This reverses "being the class
   teacher grants nothing" for this one capability, and only this one. Grading,
   creating, finalizing, and conflict resolution stay as they are.

7. **The panel's scope, restated exhaustively:** students and their enrolments;
   classes, including who is class teacher; teacher accounts, including deactivation,
   token revocation, HOD links, and the DOS grant; admin accounts; and the term
   calendar (decision 4). No academic act: no unlock, no term close, no comments.

   *Widened 2026-10-20 (#184):* subjects and their marking criteria, the subjects each
   class offers, and who teaches each subject in each class. A school set up from
   nothing in production had no other way to get them; only the demo seeder made them.
   These are school setup, not academic acts, so the last sentence above still holds.

## What was considered

**Keeping one account with layered capabilities** (ADR 0009 decision 4). Rejected.
Separation of duties is the point: the panel can create accounts and edit the roster,
so it should not share a credential with marking, and the people who use it are not
the people who mark. Two accounts for a head teacher is how a school already works
with any two systems.

**A separate `admins` table.** Rejected. One table with a `kind` column keeps one
model, one password and deactivation path, and one provider, with each door filtering
by kind. The separation lives at the two doors and in every policy, where it can be
tested, not in a duplicated schema.

**Deriving the term from planned dates** (admins enter each term's start and end, and
the device picks the term containing an assessment's date). Rejected on the
developers' own experience: schools open days either side of the Ministry's dates, so
planned dates would be wrong at both ends of every term, and a calendar the system
believes but the school does not follow is worse than none.

**Admins closing terms.** Rejected: the close starts the report cards, an academic
act. The back office has no view of whether a stream's marking is truly done.

**Any assigned teacher closing their own stream's term** (the current rule). Rejected:
in a school the exams office decides when results are final, not each subject teacher.

## Consequences

- **The sync layer changes in two places, both examined:** the assessment push
  handler checks the open term and a closed stream term on create, and the comment
  push handler checks the class teacher or DOS. Each change ships with tests on the
  server and, where the device anticipates the answer, on the device.
- **Data:** `users.kind` replaces `is_admin`; `users.directs_studies` is added;
  `unique(email)` becomes `unique(kind, email)`; the `terms` table is added. No
  production data exists yet, only development databases that `DEV_DATA_EPOCH`
  rebuilds, but the migrations are written as if it did:
  - **Accounts keep their ids.** An account with `is_admin` becomes `kind = admin` in
    place, and every other account `kind = teacher`, so every reference to a user
    (marks, conflicts, finalizes, closes, comments, unlock notes) stays valid. The
    migration refuses to run if an `is_admin` account also holds a teacher assignment,
    a moderation link, a class-teacher seat, or authored marks: such a person must be
    split into two accounts by hand first, the existing row keeping the side its
    history belongs to, so no history is silently re-attributed.
  - **No term is opened by the backfill.** Existing assessments' (year, term) pairs
    become term rows that are all ended; which term a school is in is a fact only an
    admin can state, so no term is open until one is opened in the panel. The seeder
    opens one for the demo school.
  - The seeder gains separate admin and teacher accounts, a DOS, class teachers, and
    an open term, so `DEV_DATA_EPOCH` is bumped.
- **Specs updated in the merge requests that implement them:**
  - `access-model.md`: The principle (its lifecycle paragraph already disagrees with
    the code, which allows unlock to admins only), What ADMIN gates, Class teacher,
    Moderation, and Roles.
  - `workflow.md`: Term close, Comments, and Unlock.
  - `data-model.md`: decision 3 promoted, and the new columns and table.
  - `sync-protocol.md`: assessment creation and comment commands.
- **`GET /me`** returns the teacher's HOD subjects and DOS grant in place of the admin
  flag.
- **Order of work,** each a merge request proposed before code: separate accounts;
  the term calendar on the server and in the panel; assessment creation on the device;
  the HOD and DOS roles with unlock and term close; the class teacher's remark.
- **Panel MFA** (decided for deployment) applies to admin accounts only, which this
  ADR makes the whole of the panel's audience.

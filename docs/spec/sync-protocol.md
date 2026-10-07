# Sync protocol

## Scope

Two verbs carry every synchronisable table: `POST /sync` (push, a batch of
mutations) and `GET /sync?since=` (pull, changes since a cursor). One batch
rather than one endpoint per resource, because the outbox is already an
ordered list across tables (an assessment's creation must precede its marks),
and a batch preserves that order in one round trip on a connection that might
drop mid-way; per-resource REST would mean a cursor per table and the version
check repeated in every controller instead of once. Online-only concerns
(analytics summaries, report PDFs, the assistant) stay ordinary REST and are
out of scope here.

Marks, assessments, and conflicts are pushed and pulled. A notification's
`unread` field is the one pushed field on an otherwise pull-only table. Every
other synchronisable table in `docs/spec/data-model.md` (#34), classes,
`class_subjects`, subjects, criteria, students, `enrolments`,
`teacher_assignments`, `subject_moderations`, users, results, comments,
reports, is pull-only: a device reads it and never proposes a change.
`institutions` and `unlock_notes` aren't synchronisable at all (#34's
Conventions) and never appear on either verb.

Pull is institution-wide, not scoped to a teacher's assignments, with one
exception: a notification is a personal feed (a conflict raised, an edit
blocked), so a user is sent only their own, in a first pull and in the log
alike. Grading is
unrestricted (#36), so a colleague covering an absent teacher's class needs
that class's data already on their device, not a fresh pull that happens to
include it; and a moderator must see every open cross-teacher conflict on
their subject from the start (ADR 0002 rule 5), which assignment-based
scoping wouldn't correctly serve regardless. A school's data volume is small
enough that this costs little.

## The mutation

An outbox entry, and the unit `POST /sync` accepts, is:

```json
{ "id": "<client UUID>",
  "table": "marks",
  "recordId": "<the row's id>",
  "baseVersion": 6,
  "fields": { "markKind": "score", "score": 14 },
  "at": "<ISO timestamp, informational only>" }
```

`id` is the mutation's own identity, generated once by the device, and is
what the conflict record's `side_a` or `side_b` calls `editId`; it's what
lets a device recognise which side of a conflict is its own. `recordId` is
the affected row's id, a client UUID for a device-created row, or the
deterministic UUIDv5 for a mark (#34, Grains and identity). `fields` carries
changed fields only, never a whole row (AGENTS.md). `baseVersion` is the
row's `version` when the edit was made; `0` means the row didn't exist yet
from this device's perspective, per ADR 0001 rule 5. Several edits to one
row before any acknowledgement coalesce into one entry, keeping the earliest
`baseVersion` and the union of changed fields (ADR 0001 rule 4); coalescing
happens on the device, before the entry is ever sent.

A mutation id is reused if the same entry is sent twice, which happens
whenever a response is lost on a bad connection. The server records the
outcome of every entry it decides (rule 1 below) and answers a resend with
that stored outcome, flagged `replayed`, without writing anything a second
time, rather than treating the resend as a fresh, now-stale write.

An entry is frozen once it has been sent: it keeps exactly the `fields` and
`baseVersion` it was sent with, and a later edit to the same record waits for
the acknowledgement and is based on the version the server returned.
Coalescing (ADR 0001 rule 4) therefore applies only to entries not yet sent.
A resend must carry the same replay identity: the server stores a hash of each
entry's table, record id, `baseVersion`, and `fields`, and a known id that
arrives with a different hash is `invalid`, never `replayed`, because answering
`replayed` would silently drop whatever the device had added. `at` is
informational and is not part of that identity, so a resend that differs only in
`at` is replayed normally. A
device never holds two unacknowledged entries for one record; if it did, the
second's base would be stale against the first and it would conflict with
itself.

## POST /sync

Request: `{ "entries": [ ... ] }`, in outbox order. Each entry is processed
in its own transaction, in the order sent, so one rejected entry doesn't
block the rest: a batch of thirty entries with one conflict still persists
the other twenty-nine. A batch holds at most 100 entries; more, or entries that
are not a list of objects, is a 422 for the whole request and nothing is
processed. Each entry is its own transaction, so the limit does not lengthen any
lock; it bounds a request's duration and how much a dropped response costs on a
poor connection (a full grid of about 45 students by 5 criteria is three
requests).

An entry that cannot be keyed, because its `id` or `recordId` is not a UUID or its
`table` is not a lowercase identifier of at most 64 characters, is answered
`invalid` and is not recorded, since there is nothing to record it under; its
result carries the `id` only when that was a valid UUID, so a device matches
results to entries by position. Any other rejection, including one at the
envelope (an unknown table, a server-owned field, a bad `baseVersion`), is
recorded like every outcome, so a resend of it replays the stored rejection.

Response: one result per entry, same order:

```json
{ "results": [
  { "id": "<entry id>", "status": "accepted", "version": 7 },
  { "id": "<entry id>", "status": "merged",   "version": 9 },
  { "id": "<entry id>", "status": "conflict", "current": { "...": "the row as it now stands" }, "conflictId": "<id>" },
  { "id": "<entry id>", "status": "forbidden" },
  { "id": "<entry id>", "status": "invalid",  "reason": "score exceeds criterion max" },
  { "id": "<entry id>", "status": "accepted", "version": 7, "replayed": true }
] }
```

`replayed: true` marks an answer read back from the record of an earlier
decision: every other field is what the first answer carried, except
`current`, which is read fresh. `conflictId` appears only for a mark; a
conflict on any other table has none (rule 4).

Per-entry order, with the record locked for the transaction's duration.
Before the numbered rules: the envelope is checked (the table is pushable,
every field is in that table's allowlist, none is server-owned, `baseVersion`
is a non-negative integer); then rule 1; then the record is looked up within
the caller's institution, soft-deleted rows included, since a deleted row
that looked unknown would hide the update-against-delete case; then
authorization (`forbidden`); then, for a mark, the finalized-assessment check
(`invalid`, with the `edit-blocked` notification, `workflow.md`). Then the
entry's own values are validated, so a stale entry holding an invalid value is
`invalid` and is never stored as a conflict side. Only then rules 2 to 4, and
the row that results from applying the entry is validated again (`invalid`).
So an unauthorized stale edit is never answered `conflict`, which would leave
it retained in the outbox forever. A patch is validated in the context it was
written for, and the merged row against the cell as it stands. At the current
version those are the same cell: `{score: 14}` cannot be checked without the
cell's `markKind` and its criterion. At a stale base the entry's own values are
read as the device meant them, so a lone `score` means kind `score`, the cell it
edited, not whatever kind the cell holds now; a cell since made absent then
raises the conflict instead of refusing a valid edit, while a score over the
criterion's maximum is invalid either way. Every read the entry depends on
happens inside its transaction, after the lock.

1. **Known mutation id** → the stored outcome with `replayed: true`, nothing
   written. This is what makes a lost response safe to resend, including a
   lost `conflict`. Every terminal outcome is recorded when it is decided:
   accepted, merged, a no-op, conflict, invalid, and forbidden. A rejection is
   recorded in its own transaction once the entry's has rolled back, so the
   record survives the rollback. An unexpected error is never recorded (see
   below). The lookup is not scoped by institution or user: a known id that
   belongs to another user or institution is `invalid`, never `replayed`,
   which would leak that outcome and silently drop the write.
2. **`baseVersion` equals the record's current version** → `accepted`: apply
   the fields, increment `version`, log the change (see GET /sync). A
   `baseVersion` *greater* than the current version is `invalid`, never
   merged: a device can only hold a version the server issued, so this arises
   when the server's database was rebuilt under it, and an empty set of changes
   above a base ahead of the record would otherwise read as "no overlap".
3. **`baseVersion` is `0` and the id is unknown** → create the row at
   `version: 1`; the server never stores version 0 (ADR 0001 rule 2), so
   `baseVersion: 0` never equals an existing row's version. A known id at
   `baseVersion: 0` is not a duplicate create;
   it falls through to rule 4, which is exactly how two devices creating the
   same mark cell offline (identical deterministic id) turn into an ordinary
   conflict instead of two rows.
4. **`baseVersion` is behind the current version.** If the record was
   deleted (`deletedAt` set) at any version after `baseVersion` and this
   entry doesn't itself delete it, the entry is a `conflict` outright,
   regardless of which fields it touches. Disjoint-field merging doesn't
   apply here: an edit that doesn't know its target has vanished must
   surface, per ADR 0001 rule 6, not merge silently just because its field
   names happen not to be `deletedAt`. Otherwise, look at every field whose
   stored value actually moved on that record at versions greater than
   `baseVersion` (from the change log: every write appends only the fields
   whose values actually changed, so a field a mutation merely touched without
   changing it never appears in it, which is why the mixed-merge case below
   needs no special handling. "Moved" means per write, not net: a field
   changed from A to B and back to A is in the set. A create is logged with
   every field it set, so at `baseVersion: 0` against a known id every field
   the creator set counts as moved. The delete case above looks for a log row
   above `baseVersion` whose `deletedAt` is non-null, so a restore, which logs
   `deletedAt: null`, is not a delete. Before relying on the log the server
   checks it is complete: the number of log rows for this record above
   `baseVersion` must equal the current version minus `baseVersion`, and if it
   does not, for example after a future compaction, the entry is a `conflict`,
   never a `merged`).
   If the entry's fields don't overlap that set, and at least one of them
   differs from the record's current value → `merged`: apply, increment, log,
   same as rule 2. If none of them differs and nothing overlaps, there is
   nothing to merge: it is the no-op below, accepted at the unchanged version.
   If they overlap and the values on
   both sides are equal, on any table (an `unread` flag set on two of one
   user's devices, a second finalize): if every field the entry touches,
   overlapping or disjoint, already matches the record's current value →
   **(proposed
   amendment, discussed 2026-10-03, not yet reviewed: a no-op)** the record
   itself is not touched again: no new `version`, no change-log row,
   `last_edited_by` unchanged, since the value the first side already wrote
   is the value this entry would have written, in every field it touches,
   not only the overlapping one. The entry's mutation id is still recorded
   in the processed-mutation lookup rule 1 checks, exactly as it would be
   for any other rule; a no-op skips the change-log row and the version
   bump, never the id's own record of having been handled, so a lost
   response that makes a device resend this same mutation is caught by
   rule 1 as `replayed` rather than running the match in this rule a
   second time and appending a second `conflicts` side. "First" here means
   whichever write
   produced the version this entry collided with, ordered by version
   number, never by either side's `at`; when several versions lie between the
   base and the current row, it is the write that produced the current
   version. For a mark, a `conflicts` row is still logged
   with `resolution.kind: "auto"` (ADR 0002 rule 6), recording both sides,
   so the second device sees the collision happened even though nothing
   about the record changed. The response to the second device is still
   `accepted`, carrying the record's current (unchanged) version, exactly
   as if its write had applied; from that device's own point of view the
   value it wanted is already there, and it has no reason to know a
   collision happened at all unless it later opens the conflict row. If at
   least one disjoint field's submitted value actually differs from the
   record's current value, the no-op above doesn't apply: this is
   `merged`, same as the disjoint-only case, applying every field the
   entry sent, including any that happened to already match; the mutation
   is contributing real information through that disjoint field, so it
   earns the ordinary merge, not the no-op. Treating an all-fields-match
   entry as a full `merged` instead, logging a disjoint field as "changed"
   when its value didn't move, would give a later stale edit against that
   same field something to collide with that was never a real change; the
   no-op avoids manufacturing that collision. If the values differ →
   `conflict`: nothing is written to the record itself. For a mark, a
   `conflicts` row is created holding both sides (a conflict already open on
   the cell is an open question below), and the response carries the record's
   current state so the device can raise the conflict immediately. For any
   other table there is no conflict record, since a conflict is one mark
   edited on two devices (ADR 0002): the response is `conflict` with
   `current` and no `conflictId`; no conflict record is created and the row is
   unchanged, though the mutation's own outcome is recorded like any other, so a
   resend replays it. The device drops the
   entry, adopts `current`, and shows a notice, since there is no resolution
   flow for it to retain the entry for. The
   device already shows the conflict from this rejection alone; the server
   writing the row is what lets the other teacher and any moderator see it
   on their next pull, since the server is the only party holding both
   sides at this moment. This refines ADR 0001 rule 3, which described the
   device raising the conflict but didn't say where the record lives.
5. **A soft delete is `deletedAt` in `fields`, nothing else.** Where a table
   allows a delete, a non-null value deletes with the server's own timestamp
   and null restores; it is an intent, compared as null or non-null, so
   deleting an already-deleted row is not a conflict over two timestamps. As
   an outgoing edit it follows rules 2 through 4 like any other field; it's
   only an *incoming* edit arriving against an already-deleted record that
   rule 4's delete case catches. No pushable table allows a delete today:
   `deletedAt` on a mark is `invalid` (a cleared cell is `markKind: empty`),
   and on an assessment it is `invalid` until something sends one.
6. **Authorization failure** (the acting user can't write this table, or
   the record doesn't resolve within their institution) → `forbidden`.
   **Validation failure** (a score over the criterion's max, a status
   moving backward) → `invalid` with a reason. Neither is retried
   automatically; a correction is a new mutation, not a replay. Both are
   decided before rules 2 to 4, except validation of the resulting row (see
   the order above).

Conflict actions (propose, accept, refer, resolve) are commands on `table: "conflicts"`, specified in
"Conflict commands" below. They are never field patches.

Unlocking a finalized assessment is not a mutation through this endpoint.
It's an ADMIN action (#36), almost certainly made online, that writes
`unlock_notes` and the assessment's status directly; `unlock_notes` isn't
synchronisable (#34) and was never meant to travel through the outbox.

**Field names on the wire.** Names are camelCase on the wire and each
pushable table has an explicit wire-to-column allowlist; names are never
converted generically, and the server-owned check runs on the wire name. A
finalize mutation's `finalizedAt` is informational, like `at`, and is ignored,
the server stamping `finalized_at` from its own clock. A conflict command has
no separate time field either: the device's claimed time is the entry's own
`at`, kept as the `at` of the proposal, the referral, or the resolution, so the
`resolvedAt` a device pulls is only ever the server's `resolved_at`.

**Unexpected errors** (a defect, not a rule outcome) abort the batch with a
5xx. Earlier entries are committed and recorded, so the device resends the
whole batch and they come back `replayed`; the failing entry is not recorded
and is decided afresh. A poisoned entry can stall the outbox until the defect
is fixed, which is the right pressure: a status that told the device to keep
going would hide it. (Proposed.)

**Decided while building POST /sync (3.2a).**
- A patch that changes nothing, at the current version, is `accepted` at the
  unchanged version and logs nothing; the version moves only when a value does,
  from any user (see "Decided while building the rest of POST /sync (3.2c)" below).
- An edit at the current version against a soft-deleted row is `invalid`, not
  applied to the deleted row. A stale edit against one is rule 4's delete case.
- A reference that does not resolve inside the institution (an assessment, student,
  criterion, class, or subject) is `invalid`; `forbidden` is for the record itself
  and the actor.
- On a mark, the identity fields (`assessmentId`, `studentId`, `criterionId`) may
  repeat the cell's own values but never change them; repeating them is how two
  devices creating one cell reach a conflict, so it is not refused.
- `at` is stored as sent, as a string of at most 64 characters, and is never a
  reason to reject: a longer one, or one containing a NUL, is dropped and the entry
  is decided normally.
- Values the database would refuse (a NUL byte, year 0000, a name longer than the
  column in characters, a table name outside the identifier alphabet) are answered
  `invalid`, never left to fail in the database: an entry that causes a 5xx every
  time it is sent stalls every entry behind it in the outbox.

**Decided while building rule 4 (3.2b).**
- The history rule 4 reads is `sync_changes` for the record above the base, indexed on
  `(institution_id, table, record_id, version)`. It is whole only if it holds exactly one
  row for each version from the base plus one to the current version: a row count would
  pass a duplicate that hides a gap. A history that is not whole conflicts, never merges.
- The entry's own values are validated before any rule-4 branch, which departs from
  "validation last" in the order of checks: a stale entry holding an invalid value is
  `invalid`, never stored as a conflict side. They are read in the context the entry means:
  a lone `score` is kind `score`, so a valid edit made against a cell that has since become
  absent is a conflict, not refused. The row that results is validated again at merge, against
  the cell as it stands, and `resulting()` runs only on a merge.
- The decision is a pure function of what the entry touches and what moved: a delete above
  the base conflicts outright; then completeness; then a deleted record with no delete above
  the base is `invalid`; then overlap, through merge groups (a mark's `markKind` and `score`,
  an assessment's `classId` and `subjectId` each move as one fact), with identity fields
  excluded because they never move.
- An entry that touches nothing that moved and whose values already hold is the no-op
  (`accepted` at the unchanged version, nothing written, no log row), not `merged`: merging
  would bump the version and move `last_edited_by` for a write that changed no value. The
  spec's earlier wording said `merged` for that case and is superseded.
- A mark conflict is written to `conflicts` with two flat sides, `{editId, userId, who,
  markKind, score, at, receivedAt}`. On side A, `who`, `at`, and `receivedAt` may be null (a user
  the scope hides; a write that was not a sync mutation; a hole in the log). On side B, `who` and
  `receivedAt` are always set, the token's user and the server clock, and only `at` may be null,
  since a device may omit its claim. `base_version`
  is the version side B was edited against. An auto conflict (both teachers entered the same
  value) is recorded already resolved, with `resolution` `{kind: "auto"}` and `resolved_at` set
  at decision time, because finalize refuses while any conflict is unresolved.
- Side A is the write that produced the record's current version, found by version order and
  by the change it wrote (`sync_mutations.change_seq`), never by `at`. Where that write was not
  a sync mutation (a REST write, a server write, data from before the link existed, a hole in
  the log), its `editId` is a UUIDv5 of `marks:{recordId}:{version}` (not derived from the log's
  `seq`, which may be missing exactly then), its `userId` is the user the cell credits, and its
  `at` is null. `who` is looked up through the institution scope, so a user the scope hides has
  no name.
- Side B's `receivedAt` is one server-clock reading taken when the conflict is decided: nothing
  is logged for side B, and the mutation's own row cannot come first because it references the
  conflict. So "copied from the change log" holds for side A only.
- The delete case on a mark creates no conflict record and no `conflictId`: no live value
  remains to choose between, and no pushable table can be deleted from a device. The response is
  `conflict` with the current row, and the device drops the entry, adopts the current row, and
  shows a notice, as for any non-mark conflict.
- A further conflicting entry on a cell whose conflict is already open opens a second two-sided
  conflict, with side A the producer of the current version, since both sides are required. How
  a resolution treats a second open record is 3.2c's open question. The `sync-conflict`
  notification belongs to 3.2c with `edit-blocked`.

**Decided while building the rest of POST /sync (3.2c).**
- Credit moves only with a value. A mark patch that repeats the cell's values, from any user, at
  base version not ahead of the server, changes nothing: no version, no log row,
  `last_edited_by` kept. A base ahead of the server stays `invalid`. At the current
  version (rule 2) that is now the same no-op rule 4 gives it at a stale base, so the outcome no
  longer depends on `baseVersion`. A patch that changes the value takes the credit, as before.
  This reverses the 3.2a behaviour, where a repeat from a different user bumped the version.
- An entry whose parent's create was rejected needs no rule of its own. A mark create whose
  assessment did not resolve is `invalid` ("assessmentId does not resolve"), recorded, and the
  batch goes on. `invalid` is never retried, so recovery is a new mutation with a new id once
  the parent exists; when a device stops sending an entry whose parent failed is the device's
  decision (3.4).

- Notifications over sync. A user pushes `unread` for their own notifications only: the lookup is scoped
  to the user on every path (find, lock, a replay's `current`), so another user's or institution's
  notification is indistinguishable from an unknown id. A device never creates one; any id the user cannot
  see at base 0 is `invalid` ("notifications are written by the server"). `unread` must be a true boolean.
  Two devices of one user marking it read is the no-op; one reading while another un-reads it is a conflict
  with the current row and no record.
- Finalize over sync is its own entry: `status: "finalized"` and `finalizedBy` (which must be the token's
  user), with `finalizedAt` ignored. It runs the online finalize's checks through one shared rule
  (authorize, require the stored status `scheduled`, since `in-progress` and `complete` are derived and never stored, lock the marks, refuse while a mark conflict is open). Mixed with
  another field, missing a half, a status other than `finalized`, or on a create is `invalid`. Two devices
  finalizing is the no-op, with the credit kept by the first; an already finalized or reports-generated
  assessment is the no-op too; a finalize queued before an unlock is a conflict. Unlock stays an online
  administrator action and is never a mutation.
- Server-written notifications. A mark edit refused because its assessment is finalized writes
  `edit-blocked` to the rejected teacher and each teacher assigned to the assessment's class and subject, in
  the fresh transaction that records the rejection, since the entry's own rolled back; a resend writes
  nothing. A raised mark conflict writes `sync-conflict` to its parties, once for a self-conflict; an auto
  conflict and the delete case notify nobody. A recipient gets one unread notice per kind and assessment
  (`notifications.assessment_id`), so a grid edited after finalize writes one notice, not one per cell.
- A moderator who is a party to a conflict acts only as a party (ADR 0002 amendment). A resolution against a
  mark that has moved since the conflict opened is a stale write, and a follow-up conflict records what it
  could not apply.

**Open, for the client (3.4).**
- An outbox belongs to one user; a second sign-in on a device must not push the first user's pending
  entries as itself.
- An outbox keeps a finalize as its own entry at the end of the queue. Coalescing (ADR 0001 rule 4) would
  fold it into an earlier entry for the same assessment, and the marks queued after it would then be
  refused as `edit-blocked`. The server refuses a finalize mixed with other fields, so the mistake
  surfaces instead of hiding.

## Conflict commands

A conflict is settled by four commands on `table: "conflicts"`, with `recordId` the conflict's own id and
`baseVersion` the conflict's own version. They are commands, not field patches: each names what it acts on,
is validated against the conflict's current stored state, and is answered by rule 2 only, never merged by
rule 4, so two concurrent commands cannot overwrite each other's history. An entry carries exactly one of
these in `fields`:

```json
{ "proposal":   { "byId": "<me>", "choice": { "kind": "side", "editId": "<a side's editId>" }, "note": "..." } }
{ "proposal":   { "byId": "<me>", "choice": { "kind": "corrected", "mark": { "kind": "score", "value": 9 } }, "note": "..." } }
{ "referral":   { "byId": "<me>" } }
{ "resolution": { "kind": "agreed", "proposedById": "<the pending proposer>", "acceptedById": "<me>" } }
{ "resolution": { "kind": "self", "byId": "<me>", "choice": { "...": "as a proposal's" } } }
{ "resolution": { "kind": "moderated", "byId": "<me>", "choice": { "...": "as a proposal's" }, "note": "..." } }
```

A corrected `mark` is `{kind: "score", value}` or `{kind: "absent"}`, never `empty`. The wire carries ids only, under
the client's `*Id` names, and the server fills the display names. The device's claimed time is the entry's `at`;
no nested field carries a time, and a payload that includes `receivedAt` anywhere is `invalid`. An acceptance
names only the proposer; the server copies the choice and the note from the stored pending proposal, so it never
compares what a device relays against what it holds.

What is stored. A proposal is `{byId, by, choice, note, at, receivedAt}`. A referral is
`{reason: "party", byId, by, at, receivedAt}`, or `{reason: "rounds", at, receivedAt}`; the reason is computed from
the stored proposal count (two or more means `rounds`), never taken from the device. A resolution is the shape
ADR 0002 defines for its kind, with the display names filled and the entry's `at`; `resolved_at` is the server clock.
`choice` is always rebuilt from validated pieces, never passed through as sent, and `receivedAt` is the server
clock when the command is processed.

**Who may do what.** A party is a user named on either side. A moderator is a user with a live
`subject_moderations` row for the assessment's subject. A moderator who is also a party acts only as a party.

| Actor and state | propose | accept | refer | resolve |
|---|---|---|---|---|
| Neither a party nor a moderator | forbidden | forbidden | forbidden | forbidden |
| Moderator, not a party, on a cross-teacher conflict | forbidden | forbidden | forbidden | `moderated`, at any time, referred or not; a note is required |
| Moderator, not a party, on someone else's self-conflict | forbidden | forbidden | forbidden | forbidden |
| The author of a self-conflict | invalid | invalid | invalid | `self`; no note, and a `note` key is invalid |
| Cross-teacher party, the conflict is referred | invalid | invalid | invalid | forbidden |
| Cross-teacher party, nothing pending | allowed | invalid | allowed | forbidden |
| Cross-teacher party, their own first proposal pending | invalid | invalid | allowed | forbidden |
| Cross-teacher party, their own counter pending (two proposals exist) | invalid | invalid | invalid | forbidden |
| Cross-teacher party, the other's proposal pending | allowed while fewer than two proposals exist, else invalid | allowed | allowed | forbidden |
| Anyone, the conflict already resolved (auto included) | invalid | invalid | invalid | invalid |

After a proposal and a counter, only the original proposer may accept or refer (ADR 0002 rule 4), so the author of
the counter waits; earlier, either party may refer instead of proposing or responding. A referral records the
reason and nothing else changes; a referred conflict accepts no further proposal or
referral. A resolution whose kind does not fit the conflict (`self` on a cross-teacher conflict, `moderated` on a
self-conflict) is `invalid`, and `auto` is never sent: only the server writes it.

**Validation.** A side choice must name one of the conflict's two `editId`s. A corrected score must be an integer
from 0 to the criterion's current maximum, and an accepted choice is checked against that maximum again when it is
applied. A note is required for a proposal and for a moderated resolution, non-blank after trimming, at most 2000
characters, with no NUL. `byId` and `acceptedById` must equal the token's user.

**Order.** After rule 1: the conflict is found and locked (the assessment, then the mark, then the conflict, the
order finalize uses); an id the user cannot see is `forbidden` at a nonzero base and `invalid` at base 0
("conflicts are raised by the server"); authorization is identity only, a party or a moderator of the subject; the
command's shape and the actor rules in the table; a base ahead of the conflict's version is `invalid`; a base behind
it is `conflict` with the conflict as `current` and no `conflictId`; and only at an equal version the state checks
(`invalid`) and the write. State is checked after the version, so a device that had not yet seen the other party's
proposal gets the fresh conflict, not an `invalid`.

**A resolution writes the mark.** The chosen mark is that side's `markKind` and `score`, or the corrected value, and
the mark is credited to that side's user for a side choice and to the acting user for a corrected one. It is written
through the same transaction as the conflict's own update, and both changes are logged, so a pull brings the settled
mark and its history together. If the cell already holds the chosen value the mark is not touched: no version, no
log row; the conflict's update is still logged. The mark's version when the conflict was raised is `conflicts.mark_version`, recorded
when it was raised (a follow-up conflict has no mutation of its own to read it from, since the command that
raised it is `accepted`). If the mark has moved since, the chosen value is a stale write at
that base and rule 4 decides it: nothing it touches moved, or the cell already holds it, writes nothing or applies;
an overlap, or a history that is not whole, records the resolution, leaves the mark, and raises a follow-up conflict
(side A the producer of the current version, side B the chosen value with `editId` the command's mutation id and the
credited user) and notifies its parties. A deleted mark is `invalid`. The command's outcome is `accepted` at the
conflict's new version, and its `change_seq` names the conflict's log row.

**Notifications.** A referral writes `sync-conflict` ("Conflict referred to you") to the subject's moderators who
are not parties, since the referral is what obliges them to act. A notice is deduplicated by kind, title, and
assessment, so an unread "Mark conflict to settle" notice does not hide a referral, and a second referral on the
same assessment while the first is unread adds none.

**Replay.** A command resent after the state moved on replays the stored outcome; it is not validated again. A resend
whose note was edited is a different payload under a used id and is `invalid`.

## GET /sync

`GET /sync?since=<cursor>&limit=<n>` returns changes after that cursor:

```json
{ "changes": [
  { "seq": 4183, "table": "marks", "recordId": "<id>", "version": 9,
    "fields": { "markKind": "score", "score": 12 } },
  { "seq": 4184, "table": "conflicts", "recordId": "<id>", "version": 2,
    "fields": { "resolution": { "...": "..." }, "resolvedAt": "..." } }
], "cursor": 4184, "more": false }
```

The cursor is `sync_changes.seq`, the bigserial primary key of the change
log every write to a synchronisable table appends to (#34's provisional
table, fixed here: it exists), not only the accepted, merged, and
auto-resolved outcomes of a push. A pull-only table's row changes without
ever passing through `POST /sync` at all, an admin creates a student, a
finalize writes a `result`, a report finishes generating, and each of
those writes appends to the same log the same way, since otherwise a
device would have no way to learn about them; pull doesn't distinguish a
push-caused change from a server-originated one, and the log doesn't
either. It's opaque to the client and never a timestamp; nothing about
pull compares wall-clock time. A log is what makes rule 4 above answerable
at all: "which fields changed on this record since version 6" has no
answer from `updated_at` alone, only from a per-change record of what was
touched and when in version order.

An omitted `since` (a first login) returns every current row across
every pull-only and push-pull table, as if each had just changed, rather
than a separate bootstrap endpoint; one code path applies changes whether
it's the first pull or the thousandth. `since=0` is not a first pull: any
integer, zero included, is an ordinary log pull. An institution whose log is
still empty has a high-water mark of 0, and reading 0 as "bootstrap" would make
every later pull a full scan until someone wrote. `limit` paginates a large first pull;
`more: true` means call again with the returned cursor.

A paged first pull is the one case where the returned cursor is not yet a
`seq` (ADR 0010, decision 5). The server fixes a high-water mark, the highest
`seq` when the bootstrap starts, and until the snapshot is complete returns an
opaque continuation token holding that mark and the last `(table, id)` read.
The device sends it back as `since` unchanged; the server resumes the scan
after that position. Only the final page returns the plain integer `seq`, equal
to the mark, so a device never holds a `seq` cursor while rows at or below it
are still unsent. Changes that land during the scan sit above the mark and
arrive on the first ordinary pull. A malformed token is a 422. Every row in a
first pull carries the mark as its `seq`, so `seq` is not unique within it and
a device keys on `(table, recordId)`. A page that fills exactly may be
followed by an empty final page. Field names are camelCase on the wire and
snake_case in storage, mapped at the top level only; a nested value, such as a
conflict's sides, passes through as stored.

Soft-deleted rows are included, never filtered out, because a device that
missed a delete has no other way to learn of it.

Applying a change on the device: skip it if its `version` is not greater than
the local record's, since a bootstrap row can already reflect a change whose log
entry sits above the mark, and an older delta must never roll the record back
(ADR 0010, decision 4). Otherwise patch the named fields onto the local
record and set its `version`, except any field the device's own pending
outbox entry for that record still holds. That field waits for its own
push result rather than being overwritten by someone else's pull, so a
local edit in flight is never silently clobbered by a pull that lands while
it's still unacknowledged.

## Conflict taxonomy

Auto-resolvable: both sides of a rejected write hold the same mark. Resolved
without a person, logged as `resolution.kind: "auto"` (ADR 0002 rule 6).
This is the only case the sync engine resolves alone; a differing value
never merges automatically, on either side of the wire.

Manual review: every other rejected write with an overlapping field. Handled
entirely through the propose, accept, refer, resolve mutations above, per
ADR 0002's rules for who may act.

## Audit timestamps and actor identity

> **Proposed addition, drafted 2026-10-03, not yet reviewed.** Raised
> while discussing PR #88 (what a device's clock can and can't be trusted
> for): `at`'s "informational only" label already protects correctness,
> since nothing above compares it to anything. It says nothing about
> auditability, which is a separate problem and what this section is for.
> This section amends rule 4's equal-value case (who gets credited) and
> adds a server-set value alongside what a device sends, plus two specific
> timestamp anomaly flags for an auditor. It does not change rules 1, 2,
> 3, 5, or 6.

A device's `at` stays exactly as it is: client-claimed, informational, and
never compared by any rule above for the purpose of deciding whether a
write is accepted, merged, or conflicted. A device's clock is not
trustworthy, and this spec already doesn't trust it for anything that
decides an outcome; that is settled by ADR 0001 and unaffected by anything
below. But a device's clock being untrustworthy is also exactly why an
auditor looking at a conflict later needs something that *is* trustworthy
to check `at` against, and nothing currently records one.

**Proposed: `receivedAt`, set by the server from its own clock, the
moment any write to a synchronisable table happens.** This is new data,
not already captured anywhere; `created_at`/`updated_at` are per-row, not
per-edit, and can't tell two overlapping writes apart. It applies to
every `sync_changes` row, not only a `POST /sync` push: the change log
above already carries a server-originated write (an admin creates a
student, an unlock, a finalize made through the admin action, a report
finishing) the same way it carries a push's, and the second anomaly check
below needs a `receivedAt` for whichever write produced a `baseVersion`
regardless of which path wrote it, so the stamp has to be unconditional
on every row that log appends, not only the three `POST /sync` rules. It
arrives with the Phase 3 sync implementation (issue #89), stored on the
change-log row `GET /sync` reads (`sync_changes`, already named in that
section below) and copied from there into a conflict's `side_a`/`side_b`
and into a `proposals[]` entry, alongside the existing client `at`, never
instead of it. The same applies to a conflict's `resolved_at` column: a
resolution command's device claim is the entry's own `at`, kept as the
`at` of the resolution, informational and never rejected or overwritten
(see "Conflict commands"). The server-set `resolved_at` column is
separate storage, filled from the server's own clock when the resolving
command is processed, for the identical reason `receivedAt` isn't read
from `at`. `receivedAt` and
`resolved_at` are both audit-only: neither is ever the `GET /sync`
cursor, which stays the opaque `seq` integer described below, and a
replayed mutation (rule 1) does not get a new `receivedAt`. Neither
rule's `POST /sync` response carries `receivedAt` at all; the existing
response shape above (`id`, `status`, `version`) is unchanged by this
proposal, for replay or any other rule. `receivedAt` is visible only
where this section already says it travels: the change log and the
conflict/proposal storage a pull later reads. For a replayed mutation,
what stays unchanged is the stored row in the change log, specifically
the `receivedAt` recorded there from the original write; replaying
doesn't touch it, since replaying isn't a new write.

**What this catches, and what it deliberately doesn't.** A large gap
between `at` and `receivedAt` is not a signal of anything: a teacher
marking all week offline and syncing Friday produces exactly that gap
honestly, and this file's whole offline-first premise means that has to
stay unremarkable. What's worth flagging as an anomaly for whoever
audits a conflict later, to look into rather than take as proof of
anything, is narrower, and both checks allow the same skew tolerance,
proposed at five minutes pending team agreement on the actual number,
applied identically to both, since `at` comes from a device clock this
spec already treats as untrustworthy: a clock that's merely wrong by
more than the tolerance can trip either check on a perfectly honest
edit, so neither check proves a lie, only that something about this
claim doesn't add up and is worth a look:

- `at` later than `receivedAt` on the same mutation: an edit that appears
  to come from after the server ever saw it.
- `at` earlier than the `receivedAt` of the write that produced the
  `baseVersion` this mutation edited against: an edit that appears to
  have been made before the version it's based on existed. Skipped
  entirely at `baseVersion: 0`, since there's no prior write to be
  earlier than.

Both flags are derived when the row is read for display (3.5), not stored
on it; they're a function of two timestamps already on record, not a new
fact that needs its own column.

**Be honest about what these two checks are for.** They catch an honest
clock mistake (wrong timezone, a device that never had NTP), not a
careful liar. A device that forges `at` to fall inside both windows
leaves no trace here at all, and that is fine, because these checks are
never the thing standing between a forged clock and a wrong outcome: that
job belongs entirely to attribution being decided by version order (see
the amendment to rule 4's equal-value case, directly below, and the
worked example "Two devices enter the same value, and one lies about
when" further down in Worked examples), which reads no timestamp from
anyone, ever. The residual risk after both the version-order rule and
these two checks is narrow and specific: a human auditor glancing at a
conflict row and trusting the displayed `at` over `receivedAt`. That's a
display problem, not a protocol one, and is addressed below.

**Display discipline.** `receivedAt` is the time shown as authoritative;
`at` is always labelled explicitly as device-reported, never presented as
simply "the time," and the two sides of a conflict are never sorted or
described as earlier or later by `at`, only by version order, extending
ADR 0002 rule 8's symmetric presentation (never "mine" or "theirs") to
time as well as ownership. Either anomaly flag above is shown beside the
side it applies to, not as a separate notice, so it reads as "this claim
doesn't add up" rather than as a system-wide warning.

Why not just have the device sign its claimed `at`? A signature proves
who sent a value, not when it's true; the device still has no clock worth
signing a true time with, so signing `at` would only make a forged
timestamp harder to dispute, not less forged.

**Amendment to rule 4's equal-value case: who gets credited.** See the
amendment to rule 4 itself above (POST /sync): the first side keeps
`last_edited_by`, "first" meaning whichever write produced the version
the second collided with, by version order, never by either side's `at`;
the second write is a no-op on the record, logged only as the `conflicts`
row's second side. The full reasoning, and why this is a credit question
and not the answer to clock forgery, is in the worked example "Two
devices enter the same value, and one lies about when," in Worked
examples below.

## Authorization and scoping

> **Proposed addition, drafted 2026-10-03, not yet reviewed.** The
> institution rule below is existing, settled spec. The actor-identity
> rule after it is new: raised while discussing PR #88 (see "Audit
> timestamps and actor identity") and belongs here because it's the same
> shape of rule, trust the token, never the payload, just for who, not
> which institution.

The institution comes from the authenticated token (Sanctum, #36), never
from the payload, and both verbs require the token's `sync` ability
(`docs/spec/access-model.md`, Tokens). A `recordId` that doesn't resolve
within the caller's institution behaves like an unknown id, with one
exception: primary keys are global, so at `baseVersion: 0` an id that exists
in *another* institution collides with the insert. That is `forbidden`, decided
after the rolled-back insert, not an ordinary create. At a nonzero
`baseVersion` it is `forbidden` too, since there's no record on this side to be
stale against. The residual oracle, that a base-0 create is refused only when
the id exists elsewhere, is accepted because ids are unguessable UUIDs.

Marks are unrestricted by *who*, but every reference is institution-scoped:
the assessment, the student, and the criterion must resolve in the caller's
institution, the student must be enrolled in the assessment's class and year,
and the criterion must belong to the assessment's subject, as the REST write
path checked; foreign keys do not check an institution. A notification
resolves only among the caller's own, so another user's behaves like an unknown
id. Updating an assessment is scoped like finalizing it (its creator or an
assigned teacher), not open to every authenticated user.

**Proposed: a two-part rule for who a mutation's fields can claim to be.**
A user id can legitimately appear in a payload: `workflow.md`'s finalize
mutation sends `finalizedBy`, and a conflict's `proposals[]` entries carry
`byId`, because an offline device recording its own action has to say who
did it, and an "agreed" resolution has to say who proposed and who
accepted, so the first part below is not "no user id ever appears," which
would be false, but a split by what role the id plays in that entry:

1. **A domain field naming who performed *this* mutation** (`finalizedBy`
   on a finalize, a new proposal's own `byId`, an acceptance's
   `acceptedById`) must equal the authenticated token's user; if the
   payload sends a different id in such a field, that is `invalid`, not
   silently overridden, so a device sending the wrong id finds out rather
   than having its mistake hidden. A mark edit has no field like this at
   all: it never names its own author in `fields`, since `last_edited_by`
   is not a domain fact the device reports but a server-derived attribute,
   covered by the server-owned list below.
2. **A field naming someone else as a reference to prior history** (an
   acceptance naming the proposal it accepts, an "agreed" resolution's
   `proposedBy`) is never an acting-user claim for the current mutation; the
   server accepts it only when it matches what the server's own record already
   holds for that history, and rejects the mutation as `invalid` if it doesn't.
   A device sends only its own new proposal, `{byId, choice, note}`, with its
   claimed time in the entry's `at`, never a `proposals` array (see "Conflict
   commands" below), and never a
   `receivedAt`: that is storage the server alone fills from its own clock when
   it appends the proposal. A payload that includes a `receivedAt` anywhere is
   `invalid`, the same as any other server-owned field appearing where it
   shouldn't.

Either way, a device cannot make an edit look like someone else's: it can
correctly report who did what before, and it can act only as the user its
own token authenticates, never both at once for the same field. Columns
the server alone owns (`version`, `institution_id`, `last_edited_by`,
`created_by`, `receivedAt`, `resolved_at`) are never read from the
payload at all; `fields` carrying any of them is `invalid`, rejected
outright rather than silently stripped, so a device sending one finds
out rather than having it quietly ignored. A resolution command carries
no field named `resolvedAt` at all: its device claim is the entry's `at`,
so the `resolvedAt` a device pulls is only ever the server's.

Push authorization mirrors #36 exactly: marks and assessment creation are
unrestricted for any authenticated user; updating, finalizing, or unlocking
an existing assessment is scoped to its creator or a teacher assigned to
that class and subject (`teacher_assignments`), checked as part of rule 6
above, `forbidden` otherwise. Conflict actions are scoped by the policy
mirroring `lib/conflicts.ts`, including subject-scoped moderation
(`subject_moderations`).

## Worked examples

**Two devices edit one cell.** Both are offline when they edit the same
mark, both at `baseVersion: 6`. Device A syncs first: `accepted`, the row
moves to `version: 7`. Device B syncs from the same `baseVersion: 6`: rule 4
finds `score` changed since then, and it's the one field B also changed, so
if the values differ, `conflict`, the row stays at `version: 7`, and both
devices see the conflict on their next interaction, B immediately from its
own rejection, A from its next pull.

**Two devices enter the same value, and one lies about when.** Both
devices edited the same mark while offline earlier in the week, at
`baseVersion: 6`, whose write was itself received by the server on
Monday. Device A syncs first on Friday: `accepted`, `version: 7`,
`receivedAt` 14:02. Device B syncs minutes later; its push claims
`at: 13:50`, ahead of A's real edit, because its operator set the system
clock back before syncing, hoping to look like the earlier editor. Rule 4
finds `score` changed since `baseVersion: 6`, the values match, so it is
the no-op case above: B's write changes nothing, and `last_edited_by`
stays A's, because attribution is decided by version order (A's write
produced version 7, B's collided against it) and never by either side's
claimed `at`. B's forged clock gains it nothing: it cannot buy credit for
a write that, under rule 4, was never going to overwrite A's anyway.

Whether an auditor would even notice depends on exactly what B forged.
13:50 is later than Monday's `receivedAt` for `baseVersion: 6` and earlier
than B's own `receivedAt` on Friday, so it lands inside both windows and
trips neither anomaly check; this is the "leaves no trace here at all"
case the limits paragraph above describes, and it is fine precisely
because the forgery bought B nothing regardless. Had B instead claimed an
`at` before Monday's `receivedAt`, before the version it was editing
against had even been received, that would trip the second check; had it
claimed an `at` after its own Friday `receivedAt`, that would trip the
first. Either way the `conflicts` row still holds both sides' real
`receivedAt` values, so an auditor who checks them instead of trusting
the displayed `at`, per the display discipline above, sees the true
order regardless of what either device claimed.

**A lost response.** Device A's mutation is accepted server-side, but the
response never arrives. A's outbox still holds the entry and resends it on
reconnect. The server recognises the mutation id and answers `replayed`
with the current version; nothing is written twice, and A's local state,
already updated optimistically, needed no correction.

**Two devices create the same cell.** Both are offline and both enter a
score into a mark that has never been synced. Both compute the same
deterministic id for `(assessmentId, studentId, criterionId)`. The first to
sync creates the row at `version: 1`. The second sends `baseVersion: 0`
against a now-known id, which is rule 4, not rule 3: if the two scores
differ, it's an ordinary conflict, exactly as if the row had existed all
along, rather than a duplicate-key error or a silently dropped write.

**An update against a delete.** A mark is soft-deleted (an update with
`deletedAt` set) at `version: 4`. A device that was offline before the
delete pushes a score correction against `baseVersion: 3`. The record was
deleted at a version after 3, so rule 4's delete case applies regardless of
field overlap: the correction is a `conflict`, not `merged`, even though
`score` and `deletedAt` are different field names. This is exactly why
disjoint-field merging is written as an exception for deletion rather than
a plain field-name comparison: an edit that doesn't know its target has
vanished must surface as a conflict, per ADR 0001 rule 6, not be folded in
as if the row were still there.

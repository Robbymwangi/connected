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

Pull is institution-wide, not scoped to a teacher's assignments. Grading is
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
whenever a response is lost on a bad connection. The server recognises a
known id and answers `replayed` without writing anything a second time,
rather than treating the resend as a fresh, now-stale write.

## POST /sync

Request: `{ "entries": [ ... ] }`, in outbox order. Each entry is processed
in its own transaction, in the order sent, so one rejected entry doesn't
block the rest: a batch of thirty entries with one conflict still persists
the other twenty-nine.

Response: one result per entry, same order:

```json
{ "results": [
  { "id": "<entry id>", "status": "accepted", "version": 7 },
  { "id": "<entry id>", "status": "merged",   "version": 9 },
  { "id": "<entry id>", "status": "conflict", "current": { "...": "the row as it now stands" }, "conflictId": "<id>" },
  { "id": "<entry id>", "status": "forbidden" },
  { "id": "<entry id>", "status": "invalid",  "reason": "score exceeds criterion max" },
  { "id": "<entry id>", "status": "replayed", "version": 7 }
] }
```

Per-entry rule, checked in this order, with the record locked for the
transaction's duration:

1. **Known mutation id** → `replayed`, current version returned, nothing
   written. This is what makes a lost response safe to resend.
2. **`baseVersion` equals the record's current version** → `accepted`: apply
   the fields, increment `version`, log the change (see GET /sync).
3. **`baseVersion` is `0` and the id is unknown** → create the row at
   `version: 1`. A known id at `baseVersion: 0` is not a duplicate create;
   it falls through to rule 4, which is exactly how two devices creating the
   same mark cell offline (identical deterministic id) turn into an ordinary
   conflict instead of two rows.
4. **`baseVersion` is behind the current version.** If the record was
   deleted (`deletedAt` set) at any version after `baseVersion` and this
   entry doesn't itself delete it, the entry is a `conflict` outright,
   regardless of which fields it touches. Disjoint-field merging doesn't
   apply here: an edit that doesn't know its target has vanished must
   surface, per ADR 0001 rule 6, not merge silently just because its field
   names happen not to be `deletedAt`. Otherwise, look at every field
   changed on that record at versions greater than `baseVersion` (from the
   change log). If the entry's fields don't overlap that set → `merged`:
   apply, increment, log, same as rule 2. If they overlap and the values on
   both sides are equal → `accepted`, and a `conflicts` row is logged with
   `resolution.kind: "auto"` (ADR 0002 rule 6). If they overlap and differ →
   `conflict`: nothing is written to the record itself; a `conflicts` row is
   created (or, if one is already open on this cell, this entry becomes its
   second side) holding both values, and the response carries the record's
   current state so the device can raise the conflict immediately. The
   device already shows the conflict from this rejection alone; the server
   writing the row is what lets the other teacher and any moderator see it
   on their next pull, since the server is the only party holding both
   sides at this moment. This refines ADR 0001 rule 3, which described the
   device raising the conflict but didn't say where the record lives.
5. **A soft delete is `deletedAt` in `fields`, nothing else.** As an
   outgoing edit it follows rules 2 through 4 like any other field, subject
   to ordinary disjoint-field merging; it's only an *incoming* edit arriving
   against an already-deleted record that rule 4's delete case catches.
6. **Authorization failure** (the acting user can't write this table, or
   the record doesn't resolve within their institution) → `forbidden`.
   **Validation failure** (a score over the criterion's max, a status
   moving backward) → `invalid` with a reason. Neither is retried
   automatically; a correction is a new mutation, not a replay.

Conflict actions, propose, accept, refer, resolve, are ordinary mutations
with `table: "conflicts"` and `recordId` the conflict's own id: `fields`
is `{proposals: [...]}` for a proposal, `{referral: {...}}` for a referral,
`{resolution: {...}, resolvedAt}` for an acceptance or a resolution. Each is
validated against the same policy `lib/conflicts.ts` encodes on the device
(the proposal bound of one round, who may act on a self- versus a
cross-teacher conflict, who may moderate which subject), so the rule can't
drift between the two. A resolution writes the mark and increments its
version in the same transaction as the conflict's own update, and both
changes are logged, so a pull brings the settled mark and its history
together rather than one ahead of the other.

Unlocking a finalized assessment is not a mutation through this endpoint.
It's an ADMIN action (#36), almost certainly made online, that writes
`unlock_notes` and the assessment's status directly; `unlock_notes` isn't
synchronisable (#34) and was never meant to travel through the outbox.

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

`since=0` (or omitted, for a first login) returns every current row across
every pull-only and push-pull table, as if each had just changed, rather
than a separate bootstrap endpoint; one code path applies changes whether
it's the first pull or the thousandth. `limit` paginates a large first pull;
`more: true` means call again with the returned cursor.

Soft-deleted rows are included, never filtered out, because a device that
missed a delete has no other way to learn of it.

Applying a change on the device: patch the named fields onto the local
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

## Authorization and scoping

The institution comes from the authenticated token (Sanctum, #36), never
from the payload. A `recordId` that doesn't resolve within the caller's
institution behaves exactly like an unknown id: at `baseVersion: 0` it's an
ordinary create; at a nonzero `baseVersion` it's `forbidden`, since there's
no record on this side to be stale against, consistent with how
`docs/spec/access-model.md` describes a cross-institution row as simply not
found.

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

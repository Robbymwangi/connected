# 10. Pull cursor is a change-log sequence, serialised at commit

Date: 2026-10-05

## Status

Accepted.

## Context

`GET /sync` must tell a device exactly what changed since it last pulled, including
soft deletes and changes made by the server rather than a push. Two designs were
open (`docs/spec/data-model.md`, `sync_changes`): read `updated_at` directly, or
keep a change log and use its sequence as the cursor.

A timestamp cursor loses writes. A row's `updated_at` is taken when its transaction
starts and becomes visible when it commits. If a pull advances the cursor past a
timestamp before that transaction commits, no later pull returns the row. It also
cannot answer rule 4 of the push protocol, "which fields changed on this record since
version 6", because `updated_at` is per row, not per edit. A first draft of 3.1 used
`updated_at` and was reworked for these reasons.

A log keyed by a bigserial has the same hole in a different place: two transactions
take `seq` 10 and 11, the second commits first, a pull returns 11 and moves the
cursor to it, and 10 commits afterwards and is never returned.

## Decision

1. Every write to a synchronisable table appends one row to `sync_changes`, in the
   same transaction as the write: the table, the record id, the new `version`, the
   changed fields only, and a server-set `received_at`. Pull-only tables and
   server-originated writes are logged identically to pushes.

2. The cursor is `sync_changes.seq`. It is opaque to the client. No part of pull
   compares wall-clock time. `seq` is a server-assigned bigserial primary key, the
   one exception to client-generated UUID keys: log rows are never created on a
   device, and the sequence is the cursor.

3. The append takes a transaction-scoped Postgres advisory lock, held until the
   writing transaction commits or rolls back. Writers to synchronisable tables
   therefore serialise at the append, so `seq` order equals commit order and a
   pull can never advance past a change that has yet to commit. A rolled-back
   write leaves no log row and no gap that matters. (Amended 2026-10-06: where the
   lock is taken and its key; see Amendments.)

4. `since=0` reads the live tables, one change per current row, in the same
   `{seq, table, recordId, version, fields}` shape. The high-water mark is the
   highest `seq` at the start of the bootstrap, taken before the first table scan,
   so a change that lands during the scan is returned again by a later pull rather
   than missed. A bootstrap row may already reflect a change whose log entry lies
   above the mark, so the device applies a change only when its `version` exceeds
   the local record's; versions only increase, so an older delta can never roll a
   record back and re-applying one is a no-op.

5. A bootstrap larger than `limit` is paged by a continuation, not by the sequence
   cursor. Until the snapshot is complete, the response carries `more: true` and an
   opaque `cursor` encoding the fixed high-water mark and the last `(table, id)`
   read, in a fixed table order and id order. The client sends it back as `since`;
   the server resumes the scan after that position, with the mark unchanged. Only
   the final page returns a plain integer `seq` cursor, equal to the mark, and
   ordinary pulls take over from there. A client therefore never holds a `seq`
   cursor while rows at or below it are still unsent. The client treats `cursor`
   as opaque throughout, so the token needs no separate field.

## Consequences

- Writes to synchronisable tables are serialised through one lock. A school's write
  volume is small, so this costs little; it would not suit a multi-region
  deployment, which is not this system.
- A long transaction holds the lock and blocks other writers until it ends. Writes
  stay short. (Amended 2026-10-06: the lock is now taken at the start of the
  transaction, not at the append; see Amendments.)
- The log grows with every edit. Compaction is future work; nothing here prevents
  it, provided a device's cursor is never moved past a pruned range.
- A paged bootstrap carries a token for the scan position; the server validates it
  and, if it is malformed, answers 422 rather than guessing a position.
- Rule 4 of `POST /sync` has the per-field history it needs, from the same log.
- Rejected alternative: pulling only up to the lowest in-flight `seq`. It avoids
  serialising writers but needs the server to know which transactions are in
  flight, which is harder to reason about and to defend.

## Amendments

### 2026-10-06: the lock is taken first, per institution

Decision 3 said the lock is taken at the append. Reading the write paths for the
implementation showed that this can deadlock, because some transactions lock a row
before they write. The mark PUT is one: it locks its assessment row
(`lockEditableAssessment`) and then writes the mark. A bare model write such as
`AssessmentsController::update()` calls `$assessment->update()` with no
transaction of its own. If a model write took the advisory lock before its row
update, the interleaving is:

1. The PUT locks the assessment row.
2. The bare update takes the advisory lock, then waits for the assessment row.
3. The PUT writes the mark, which appends to the log, and waits for the advisory lock.

Each holds what the other needs, and Postgres aborts one. Taking the lock at the
append has the mirror-image problem for a transaction that appends and then
touches a row another writer already holds. The fix is one lock order everywhere:

- The advisory lock is the first statement of every transaction that writes a
  synchronisable table, before any row is locked or written. Rows come after it.
- `SyncLog::transaction()` is the only way to open a transaction in `app/`, so the
  order is structural, not a convention. A test fails on any other transaction
  call in `app/`, and another fails if a table with a `version` column has no
  `Syncable` model. The lock is re-entrant, and a nested call joins the open
  transaction instead of adding a savepoint.
- The key is per institution (`hashtextextended(institution_id, 0)`). A pull is
  institution-scoped, so only one institution's rows need a commit order. `seq` is
  global and other institutions' writes leave gaps in it; those are harmless
  because a pull never reads another institution's rows. A hash collision between
  two institutions would cost throughput, never correctness.
- A log row never carries a hidden attribute (a password hash), because
  `getDirty()` and `getAttributes()` return hidden columns (only `toArray()`
  and `toJson()` honour `$hidden`), so the append excludes them explicitly, and the
  log is pulled by every device in the school. A write whose only change is hidden
  appends nothing.

The remaining cost is as before: writers in one institution serialise, and a long
transaction holds the lock until it ends.

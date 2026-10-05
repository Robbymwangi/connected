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
   write leaves no log row and no gap that matters.

4. `since=0` reads the live tables, one change per current row, in the same
   `{seq, table, recordId, version, fields}` shape. Its cursor is the highest `seq`
   read before the table scan, so a change that lands during the scan is returned
   again by the next pull rather than missed. Applying a change is idempotent.

## Consequences

- Writes to synchronisable tables are serialised through one lock. A school's write
  volume is small, so this costs little; it would not suit a multi-region
  deployment, which is not this system.
- A long transaction holds the lock and blocks other writers until it ends. Writes
  stay short, and the lock is taken at the append, not at the start of the
  transaction, to keep the window small.
- The log grows with every edit. Compaction is future work; nothing here prevents
  it, provided a device's cursor is never moved past a pruned range.
- Rule 4 of `POST /sync` has the per-field history it needs, from the same log.
- Rejected alternative: pulling only up to the lowest in-flight `seq`. It avoids
  serialising writers but needs the server to know which transactions are in
  flight, which is harder to reason about and to defend.

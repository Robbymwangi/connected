# 1. Record versioning and conflict detection

Date: 2026-09-16

## Status

Accepted.

## Context

CLAUDE.md requires an integer `version` on every synchronisable record, incremented
server-side, with conflicts detected by a stale base version and never by comparing
timestamps: clock skew across teacher devices makes wall-clock comparison unsound.

The frontend types carried no such field. The Sync screen has nothing to display
without it, reviewers flag its absence on every merge request that touches a record
type, and the semantics of a record that has never reached the server were undecided.
This decision fixes them once, before the local store and outbox are built.

## Decision

1. Every synchronisable record carries `version: number`. Only the server sets it:
   each accepted write increments it by one and returns the new value.

2. `version: 0` means never acknowledged by the server. A record created on a device
   is `0` until its first accepted upload. The server never assigns `0`.

3. An outbox entry carries the record id, `baseVersion` (the record's `version` when
   the edit was made), and the changed fields only. The server accepts the entry when
   `baseVersion` equals the record's current version, then increments. Otherwise it
   rejects with its current copy, and the device raises an active conflict holding
   both sides and the `baseVersion` the edit was made against.

4. Several edits to one record before any acknowledgement merge into one outbox
   entry, keeping the earliest `baseVersion` and the union of changed fields.

5. Creating a record is an entry with `baseVersion: 0`. The server accepts it when the
   id is unknown and returns `version: 1`. Ids are client-generated UUIDs, so an
   unknown id is the normal case and a known one is a replay.

6. A soft delete is an ordinary update carrying `deletedAt`, versioned like any other,
   so update-against-delete is a stale base version like any other conflict.

7. A record's displayed sync state derives from version and outbox: `version === 0`
   or a pending entry means local; otherwise synced. Timestamps are shown as
   information and never compared.

## Consequences

- `Assessment` gains `version`; `ActiveConflict` gains `baseVersion`. Fixtures seed
  synced records at `version >= 1` and created records at `0`. The marking grid's
  per-cell `sync` field becomes derived (rule 7) once the outbox exists.
- Resolving a conflict is itself an outbox entry against the server's current
  version, so a resolved cell stays local until acknowledged. The grid already
  behaves this way.
- The Laravel API must reject a write whose `baseVersion` is stale and return the
  current record in the rejection. That is a constraint on the API design, recorded
  here so the two halves agree.
- A nullable `version` was considered and rejected: `0` as the sentinel keeps every
  comparison a plain integer comparison with no null guard.

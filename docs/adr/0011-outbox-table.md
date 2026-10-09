# 11. Local writes wait in an outbox table; the record keeps only what the screen shows

Date: 2026-10-09

## Status

Proposed.

## Context

A local write must reach the server as a mutation: its own id, the record id, the base
`version` the teacher saw, and the changed fields only (`docs/spec/sync-protocol.md`,
"The mutation"). Build-plan 3.4 first said to build the outbox on the markers the local
store already keeps on the record (`pendingBaseVersion`, `pendingFields`,
`pendingFinalize`) and not to add a second local-write store.

Three rules in the protocol do not fit a marker on the record:

1. **An entry is frozen once sent.** It keeps exactly the `fields` and `baseVersion` it
   was sent with, and a resend carries the same replay identity, or the server answers
   `invalid`. The record's value keeps changing as the teacher keeps editing, so the
   record cannot also be the frozen copy.
2. **One record can have several entries at once.** An unsent assessment create, then a
   finalize that must be its own trailing entry; a conflict with a proposal and then a
   referral queued offline; a sent mark entry and a later edit whose base is unknown
   until the acknowledgement arrives.
3. **Order matters across tables.** An assessment's create must precede its marks, and a
   finalize must follow the marks it covers, or those marks are refused as
   `edit-blocked`.

The markers also have a defect that a table fixes: finalizing an assessment that
already exists folds `status`, `finalizedBy`, and `finalizedAt` into the same
`pendingFields` as any other edit. The server answers that `invalid`, because a
finalize is sent on its own.

## Decision

1. **An `outbox` table in the per-user Dexie database is the only source of what is to
   be sent.** An entry holds `{seq, id, table, recordId, kind, baseVersion, fields, at,
   state}`. `seq` is a local ascending key and gives the send order; `id` is the mutation
   id, a client UUID, unique. `kind` is `patch`, `finalize`, or `command`. `state` is
   `queued`, `sent`, `conflict`, `acked`, or `failed`.

2. **The record remains the only thing the screen and the pull read.** The outbox holds
   the protocol envelope and nothing the UI renders from. The markers
   `pendingBaseVersion`, `pendingFields`, and `pendingFinalize` stop being authorities
   and are removed. They are not kept as caches beside the outbox, because two copies of
   one fact will eventually disagree. The record keeps `sync: 'pending'` and
   `localAuthor` as display flags, written in the same transaction as the entry.

3. **Coalescing applies to unsent entries only** (ADR 0001 rule 4). A patch folds into
   the record's trailing entry when that entry is a `queued` patch: earliest base, union
   of fields, newest value wins, same mutation id. A `sent` entry is never changed. A
   finalize is always its own entry and nothing folds into or after it. A conflict
   command is never coalesced.

4. **A follow-up behind an open entry has no base yet.** When a record has a `queued`,
   `sent`, or `conflict` entry, a new entry gets `baseVersion: null` and takes the
   version the server returns when the earlier entry is answered. A device therefore
   never holds two unacknowledged entries for one record with a base that is stale
   against the first, which would make it conflict with itself.

5. **Every enqueue runs in the transaction that writes the record.** The two commit or
   roll back together.

6. **Commands are entries, not patches.** A propose, refer, accept, or resolve is an
   entry on `table: "conflicts"`, with the conflict's own version as its base, or none
   while an earlier command on that conflict is open, since the server raises the
   conflict's version when it answers (decision 4). A
   resolution enqueues no mark patch; the server applies the chosen value and the settled
   mark arrives by pull.

7. **Schema changes.** Dexie version 2 adds the table. A later version moves existing
   markers into entries, in the order assessment creates, marks, finalizes, and splits a
   folded finalize into a patch and a trailing finalize. It ships with the writers that
   stop producing markers, so a database is never left holding markers nothing converts.

This record departs from the earlier wording of build-plan 3.4. The intent of that
instruction was to avoid two stores that disagree about a value; it is kept by decision 2.

## Consequences

- Freezing, resending byte-identically, and keeping a conflicted entry until it
  resolves are properties of an entry, with no special case on the record.
- The pull must not overwrite a field the outbox is protecting. Today it keeps the local
  value but still advances `version`, so a cell can stay wrong after an outcome other than
  a plain accept. The record therefore gains a `serverShadow` of the server's value for
  protected fields, which is adopted when the entry is settled. This is built with the
  writers, not here.
- The shadow records the version each of its values was read at (`serverShadowAt`). When
  a pull overtakes an acknowledgement, that is how a settling push tells the value seeded
  when the teacher edited, which it may revert to, from a newer one a pull has since
  landed, which it must keep.
- The Sync screen's counts (pending, conflicted, failed) are a query over the outbox. A
  record's own `sync: 'pending'` is a display flag that is true exactly when the outbox
  holds an open patch or finalize entry for it, written only in the transaction that
  writes the entry and checked by a test helper after every writer, migration, and pull.
  The grid reads the flag, not the outbox, so a later state change on an entry does not
  re-run the screen's query.
- One more table and one more transaction participant in every local write. Writes are
  small, so the cost is low.
- Tests need an IndexedDB implementation under Node. `fake-indexeddb`, a dev dependency,
  provides one; the offline end-to-end test in Playwright covers real IndexedDB in a
  real browser.
- Rejected alternative: freeze a `sentEntry` on the record. It copies the values just as
  a table would, then needs an array of entries per record for the cases in the Context,
  and a global sequence counter in `metadata` merged across four tables to keep the
  create, marks, finalize order. That is an outbox table spread across the records.
- Rejected alternative: keep the markers and add a table beside them. Two sources of
  truth for one fact.
- Not decided here: the lifetime of `failed` entries beyond keeping them for the Sync
  screen, and where notices for non-mark conflicts live (a local `metadata` list for
  now, since `notifications` is server-written). Both are revisited with 3.5.

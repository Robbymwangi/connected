# 6. We write the synchronisation layer ourselves; libraries may store and carry, never decide

Date: 2026-10-22

## Status

Accepted 2026-10-22. Records a decision taken with ADR 0000 on 14 September 2026 and
built as `docs/build-plan.md` Phase 3 (3.1 to 3.5). Written up after the fact: ADR 0000
deferred it to this file, which was never written until now. The evidence it promises
from the simulation (decision 3) is still owed.

## Context

On Amplify Gen 1, DataStore was the sync layer: it kept the local copy, queued writes,
and settled concurrent edits through AppSync's conflict handlers, whose strategies are
configured, not written. ADR 0000 moved the project to Laravel and PostgreSQL for two
reasons of its own, and recorded that nothing in the new stack syncs a device the way
DataStore did. It left the question of who does that to this ADR.

The question matters more here than in most projects, for two reasons.

- **It is the examined contribution.** `AGENTS.md` names the offline synchronisation
  layer as what the examination scrutinises: the local store and outbox, the version
  check, field-level merge, the conflict flow, and the server side that accepts or
  rejects a write. A component that does any of these for us is a component we cannot
  defend line by line.
- **The domain does not tolerate a silent winner.** A mark is a number a teacher is
  answerable for. When two teachers enter different numbers for one cell, the right
  outcome is that people see the disagreement and settle it (ADR 0002), not that an
  algorithm picks one. Most sync engines are built to make conflicts disappear; this
  system has to make the real ones visible.

Three kinds of answer were available: keep a managed sync service, adopt a sync engine
or replication library, or write the layer ourselves on top of plain storage.

## Decision

1. **We write the synchronisation layer.** Its parts, each with its own record:
   - **Versions, not clocks** (ADR 0001): every synchronisable row carries an integer
     `version` that only the server increments. A write names the version it was based
     on; a stale base is how a conflict is detected.
   - **The outbox** (ADR 0011): every local write is a mutation in a Dexie table, with
     its own id, the record, its base version, and the changed fields only, sent in
     order and resent unchanged until the server answers.
   - **Push** (`POST /api/sync`, `docs/spec/sync-protocol.md`): each mutation is judged
     in its own transaction and answered `accepted`, `merged`, `conflict`, or a
     rejection. Disjoint field edits merge; an overlapping field with different values
     is a conflict; equal values are a no-op. A replayed mutation id is answered
     without writing anything.
   - **Pull** (`GET /api/sync`, ADR 0010): a device reads the server's change log from
     its cursor, including soft deletes and changes the server made itself.
   - **Conflicts** (ADR 0002): a mark conflict is a record, synced like any other, and
     resolved by the people involved, with proposals, referral, and a moderator.
   - **The sync runner** (`lib/syncRunner.ts`): when to push and pull, backoff after a
     failure, and one tab at a time per account.

2. **The line between ours and a library's: a library may store or carry data; it may
   never decide whether a write is accepted, merged, or conflicted.** By that line:
   - Dexie is allowed. It stores the local tables and the outbox in IndexedDB and runs
     their transactions. Its sync add-on, Dexie Cloud, is not used.
   - Laravel, Eloquent, and PostgreSQL are allowed. They give us transactions, the
     advisory lock that serialises a school's pushes, and the change log's sequence.
   - Workbox is allowed (ADR 0004). It caches the application, never data.
   - Anything that resolves concurrent edits, chooses a winner, or owns the replication
     protocol is not.

3. **Server-incremented versions over wall-clock timestamps, and the simulation as the
   evidence.** Teacher devices go days without a network and their clocks drift, so a
   rule that compares timestamps decides outcomes on numbers nobody can trust. ADR 0001
   makes that argument from reasoning. `docs/build-plan.md` 5.1 is to make it from
   measurement: a standalone Python simulation that replays synthetic edits with known
   ground truth through last-writer-wins on device clocks, a vector-clock resolver, and
   this system's own rules, across increasing clock skew, and scores how many edits each
   loses silently and how many real conflicts each detects. **It has not been built
   yet.** Until it is, this decision rests on the reasoning in ADR 0001 and on the
   tests of the rules themselves, and should be defended as such.

4. **No automatic merge of a contested value.** Disjoint fields merge, because nothing is
   lost. Two different values for one field never merge: the server records a conflict
   with both sides, and the people who own them settle it. Equal values are the one
   case the engine settles alone (ADR 0002 rule 6), because there is nothing to choose.

## Considered and rejected

- **Keeping a managed sync service** (DataStore on AppSync). Not available on the stack
  ADR 0000 chose, and the reason that stack was chosen: behaviour configured in a
  console is behaviour we cannot read, test, or explain.
- **Replication engines** (RxDB replication, ElectricSQL, PowerSync, Dexie Cloud). Each
  owns the protocol between device and server and resolves conflicts by its own rule.
  Adopting one would leave nothing of the examined contribution for us to write, and
  each would need bending to surface a conflict to people rather than settle it.
- **CRDTs** (Automerge, Yjs). A CRDT guarantees that every replica converges on the
  same value; it does not guarantee that the value is right. For a single numeric cell
  any convergence rule picks one teacher's number without anyone seeing the other,
  which is exactly the silent winner this domain cannot accept. CRDTs suit text and
  collections that can absorb both edits; a mark cannot.
- **Last-writer-wins on device timestamps.** The simplest engine to write, and the one
  that loses the most data silently under clock skew. It is the baseline the simulation
  is built to measure against, not a design.
- **Vector clocks.** They detect concurrency without trusting wall clocks, which is
  their strength, but every device must carry and compare a clock per writer. With a
  single server that already orders every accepted write, one integer per row gives
  the same detection with far less to store and explain. The simulation includes a
  vector-clock resolver so the comparison is shown, not asserted.

## Consequences

- We own the layer's correctness. Every rule is written down in
  `docs/spec/sync-protocol.md` and tested on both sides: Pest for each push verdict and
  the change log, Vitest for the outbox, the runner, reconciliation, and a two-device
  test (`syncRunner.twin.test.ts`). A defect here is ours to find, with no vendor to
  blame or fix it.
- Each part can be explained at the examination from its own file, because each part
  is code we wrote, and each decision behind it has its own ADR.
- The server is the one ordering point. A device never syncs with another device
  directly; two teachers who never reach the server never see each other's edits.
  That is acceptable in a school, where the server is reachable often enough.
- Sync is pull on a timer and on events (sign-in, a link coming back, a local write,
  the tab becoming visible), not pushed from the server. A change reaches another
  device within the runner's tick (two minutes) once both are online. Nothing in a
  school's day needs it faster.
- The simulation (5.1) is owed. This record cites it as its evidence; until it exists,
  that part of the defence is reasoning, not measurement.

# 2. Who may resolve a mark conflict

Date: 2026-09-16

## Status

Accepted.

## Context

A conflict (ADR 0001) is one mark edited on two devices from the same base version.
The Figma export resolved it with "Keep mine" and "Keep theirs" buttons on whichever
device saw it. That hands the decision to one of the two parties, and a party will
keep their own number. The decision must be impartial, must work with no
connectivity, and must leave a record a school can stand behind.

Two situations look the same on screen and are not. Most conflicts in an
offline-first application are one teacher's own edits from two devices; there is no
second party and nothing to be impartial about. A conflict between two different
teachers is a moderation matter.

## Decision

1. A conflict's parties are the authors of its two sides.

2. When both sides have the same author, that author resolves directly: either value
   or a corrected one, no justification required.

3. When the authors differ, neither may resolve alone. A party may propose a
   resolution (either value, or a corrected one) with a required note. The proposal
   syncs like any mutation. The other party may accept it, which applies it, or
   counter-propose with a note. A resolution applies only on acceptance and is
   recorded with both names. Every proposal is kept on the record.

4. Agreement is bounded to one proposal each. After a proposal and a counter, the
   original proposer may accept the counter or refer the conflict to a moderator;
   there is no third proposal. Either party may refer at any earlier point instead
   of proposing or responding. A referred conflict accepts no further proposals.
   The bound is a count of proposals held in the record, so it needs no clock.

5. A user holding the moderator role for the assessment (subject head or head of
   department) may resolve a cross-teacher conflict outright at any time, with a
   required note, and sees every open cross-teacher conflict from the start; a
   referral is what obliges them to. Recorded as moderated, with their name and the
   proposals that preceded it.

6. The sync engine resolves a conflict without a person only when both sides hold
   the same mark. Differing values are never merged automatically.

7. Resolution history is a read-only audit record. A mark that is wrong after
   resolution is corrected in the marking grid as a new versioned mutation, which
   may raise a new conflict through the ordinary path. Contesting or reopening a
   resolution is not a feature.

8. The interface presents both sides symmetrically, by value and author. Actions name
   the value and whose it is; they never say "mine" or "theirs".

## Consequences

- An active conflict carries its list of proposals and an optional referral. A
  resolution records how it was reached: auto, self, agreed (proposed by, accepted
  by), or moderated; history keeps the proposals and the referral with it.
- Proposals and acceptances are outbox entries; the server applies an acceptance
  against the conflict's base version like any other write.
- Roles are needed: for now a `canModerate` flag on the user fixture; the real role
  model comes with authentication.
- The marking grid's conflict dialog and the Sync screen share one set of actions,
  so the policy cannot drift between them.
- If the agreement flow proves unworkable in practice, this decision is superseded by
  a later ADR; the model carries enough to fall back to moderator-only resolution
  without losing history.

## Amendments

### 2026-10-06: a conflict is a mark conflict

This ADR defines a conflict as one mark edited on two devices, and a conflict record
(`conflicts.mark_id`) is always about a mark. The sync protocol also rejects a stale,
overlapping write to any other pushable table (an assessment). Such a rejection
raises no conflict record and enters none of the flows above: the server answers
`conflict` with the current row, no conflict record is created and the row is
unchanged (the mutation's own outcome is still recorded, like every outcome, so a
retry replays it), and the device drops the entry,
adopts the current row, and shows a notice. Disjoint edits still merge, and an equal
value is a no-op, on every table. Widening the conflicts table to any record was
considered and rejected: it would rewrite this ADR's parties, proposals, and
moderation rules for tables where nothing in the product needs them.

### 2026-10-07: moderation is per subject, a moderator who is a party acts as a party, and a resolution against a mark that has moved is a stale write

Three clarifications for the server that implements this policy (`docs/spec/sync-protocol.md`,
"Conflict commands").

1. **Moderation is per subject.** `docs/spec/access-model.md` grants it through `subject_moderations`, one
   subject at a time. The client derives a single `canModerate` flag from "moderates any subject"
   (`frontend/src/lib/session.ts`), which would let a moderator of Maths settle a Science conflict. The
   server checks the assessment's own subject, and the client follows in 3.5.
2. **A moderator who is also a party to the conflict acts only as a party.** Rule 3 says neither party may
   resolve alone, and rule 5's whole premise is an impartial decision-maker. Such a user may propose,
   accept, and refer like any party, and may not resolve the conflict outright. This changes the client's
   `abilityOf` (a global moderator who is a party currently resolves) and `canRefer` (which excludes every
   moderator, so a moderator who is a party could not even refer it to someone else). If nobody else
   moderates the subject, an administrator grants the role to someone who is not a party.
   The same rule 4 reading applies to the counter: after a proposal and a counter, only the original
   proposer may accept it or refer the conflict, and the author of the counter waits. The client's
   `canRefer` lets any party refer at that point, so it changes too.
3. **A resolution against a mark that has moved since the conflict opened is a stale write.** The chosen
   value is a write made against the mark's version when the conflict was raised, so the ordinary rule 4
   decides it: applied if nothing it touches moved, skipped if the cell already holds it, and otherwise
   recorded without touching the mark, with a follow-up conflict raised between the producer of the current
   version and the chosen value. The resolution is always recorded, so every conflict can close, and
   finalize, which refuses while any conflict is open, stays satisfiable. Two conflicts on one cell are
   settled one at a time. If resolving the first moves the mark, the second's resolution is a stale write: the
   no-op, applied if nothing it touches moved, or a follow-up conflict. If the first chose the value the cell
   already held, the mark did not move and the second applies directly. Nothing is overwritten silently. No new resolution kind: the four stay `auto`,
   `self`, `agreed`, and `moderated`.


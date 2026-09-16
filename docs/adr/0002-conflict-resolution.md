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

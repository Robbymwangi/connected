import { score, type Mark } from '../lib/grading'

/* One author's side of a contested mark. `at` is shown to the user as information
   and plays no part in detection or resolution: detection is a stale base version,
   resolution is a decision, and clocks on different devices disagree.
   See docs/adr/0001-record-versioning.md and 0002-conflict-resolution.md. */
export type ConflictSide = {
  /* The edit that produced this side: stable across devices, unlike `mine` and
     `theirs`, which name the sides as seen from this device and swap on the other. */
  editId: string
  /* Identity is the user id; `who` is the display name for the audit text. */
  userId: string
  who: string
  mark: Mark
  at: string
}

/* What a resolution settles on: one of the two edits, or a corrected mark. A choice
   names the edit, never "mine" or "theirs", so it means the same thing on every
   device it syncs to. */
export type Choice =
  | { kind: 'side'; editId: string }
  | { kind: 'corrected'; mark: Mark }

/* A cross-teacher conflict is settled by agreement: one party proposes with a
   note, the other accepts or counters. One proposal each; then it is referred. */
export type Proposal = {
  byId: string
  by: string
  choice: Choice
  note: string
  at: string
}

/* Handed to a moderator: after the bounded rounds ran out, or by a party's choice. */
export type Referral =
  | { reason: 'rounds'; at: string }
  | { reason: 'party'; byId: string; by: string; at: string }

type ConflictRecord = {
  id: string
  assessmentId: string
  studentId: string
  criterionId: string
  student: string
  criterion: string
  assessment: string
  mine: ConflictSide
  theirs: ConflictSide
  /* Every proposal made, oldest first; the last one is the pending one. */
  proposals: Proposal[]
  referral?: Referral
}

export type ActiveConflict = ConflictRecord & {
  /* The record version this device's edit was made against. */
  baseVersion: number
}

/* How a conflict was settled. Actors carry their id and their display name. */
export type Resolution =
  | { kind: 'auto' }
  | { kind: 'self'; byId: string; by: string; choice: Choice }
  | { kind: 'agreed'; proposedById: string; proposedBy: string; acceptedById: string; acceptedBy: string; choice: Choice; note: string }
  | { kind: 'moderated'; byId: string; by: string; choice: Choice; note: string }

export type HistoricalConflict = ConflictRecord & {
  resolution: Resolution
  resolvedAt: string
}

const me = { userId: 'u-1', who: 'John Doe' }
const akinyi = { userId: 'u-2', who: 'Ms. Akinyi' }
const kamau = { userId: 'u-3', who: 'Mr. Kamau' }

/* Three active conflicts covering the paths in ADR 0002: one open between two
   teachers, one where the other teacher has already proposed, and one that is the
   same teacher's edits from two devices. */
export const activeConflicts: ActiveConflict[] = [
  {
    id: 'c-1',
    assessmentId: 'a1',
    baseVersion: 6,
    studentId: 's1',
    criterionId: 'c3',
    student: 'Wanjiku Njoroge',
    criterion: 'Oral Fluency',
    assessment: 'English CAT 2, Term 2',
    mine: { editId: 'e-c-1-m', ...me, mark: score(14), at: '2026-08-27T14:32:00' },
    theirs: { editId: 'e-c-1-t', ...akinyi, mark: score(11), at: '2026-08-27T16:05:00' },
    proposals: [],
  },
  {
    id: 'c-2',
    assessmentId: 'a1',
    baseVersion: 6,
    studentId: 's2',
    criterionId: 'c2',
    student: 'Kofi Mensah',
    criterion: 'Written Expr.',
    assessment: 'English CAT 2, Term 2',
    mine: { editId: 'e-c-2-m', ...me, mark: score(8), at: '2026-08-27T14:45:00' },
    theirs: { editId: 'e-c-2-t', ...akinyi, mark: score(10), at: '2026-08-27T16:12:00' },
    proposals: [
      {
        byId: akinyi.userId,
        by: akinyi.who,
        choice: { kind: 'side', editId: 'e-c-2-t' },
        note: 'I re-marked the second paragraph against the rubric; the argument is developed, which is a 10.',
        at: '2026-08-27T17:02:00',
      },
    ],
  },
  {
    id: 'c-3',
    assessmentId: 'a1',
    baseVersion: 6,
    studentId: 's6',
    criterionId: 'c1',
    student: 'Amara Kamau',
    criterion: 'Comprehension',
    assessment: 'English CAT 2, Term 2',
    mine: { editId: 'e-c-3-m', ...me, mark: score(11), at: '2026-08-27T14:50:00' },
    theirs: { editId: 'e-c-3-t', ...me, mark: score(12), at: '2026-08-27T15:40:00' },
    proposals: [],
  },
]

/* Settled conflicts from the previous assessment, newest first, as the screen
   lists them and the store prepends them. */
export const resolvedConflicts: HistoricalConflict[] = [
  {
    id: 'h-4',
    assessmentId: 'a7',
    studentId: 's2',
    criterionId: 'c2',
    student: 'Kofi Mensah',
    criterion: 'Written Expr.',
    assessment: 'English CAT 1, Term 1',
    mine: { editId: 'e-h-4-m', ...me, mark: score(7), at: '2026-07-14T10:12:00' },
    theirs: { editId: 'e-h-4-t', ...akinyi, mark: score(11), at: '2026-07-14T13:20:00' },
    proposals: [
      {
        byId: me.userId,
        by: me.who,
        choice: { kind: 'side', editId: 'e-h-4-m' },
        note: 'Three of the five sentences are fragments; the rubric caps that at 7.',
        at: '2026-07-14T16:00:00',
      },
      {
        byId: akinyi.userId,
        by: akinyi.who,
        choice: { kind: 'corrected', mark: score(9) },
        note: 'Fragments, yes, but the ideas are sequenced; 9 recognises both.',
        at: '2026-07-15T08:30:00',
      },
    ],
    referral: { reason: 'rounds', at: '2026-07-15T09:05:00' },
    resolution: {
      kind: 'moderated',
      byId: kamau.userId,
      by: kamau.who,
      choice: { kind: 'corrected', mark: score(8) },
      note: 'Re-marked blind against the rubric with both of you present. 8.',
    },
    resolvedAt: '2026-07-16T11:30:00',
  },
  {
    id: 'h-2',
    assessmentId: 'a7',
    studentId: 's4',
    criterionId: 'c3',
    student: 'Liam Osei',
    criterion: 'Oral Fluency',
    assessment: 'English CAT 1, Term 1',
    mine: { editId: 'e-h-2-m', ...me, mark: score(12), at: '2026-07-15T10:30:00' },
    theirs: { editId: 'e-h-2-t', ...akinyi, mark: score(14), at: '2026-07-15T13:44:00' },
    proposals: [
      {
        byId: akinyi.userId,
        by: akinyi.who,
        choice: { kind: 'side', editId: 'e-h-2-t' },
        note: 'Liam re-read the passage aloud during the moderation session; 14 is the agreed mark.',
        at: '2026-07-15T14:50:00',
      },
    ],
    resolution: {
      kind: 'agreed',
      proposedById: akinyi.userId,
      proposedBy: akinyi.who,
      acceptedById: me.userId,
      acceptedBy: me.who,
      choice: { kind: 'side', editId: 'e-h-2-t' },
      note: 'Liam re-read the passage aloud during the moderation session; 14 is the agreed mark.',
    },
    resolvedAt: '2026-07-15T15:10:00',
  },
  {
    id: 'h-3',
    assessmentId: 'a7',
    studentId: 's5',
    criterionId: 'c4',
    student: 'Fatou Diallo',
    criterion: 'Vocabulary',
    assessment: 'English CAT 1, Term 1',
    mine: { editId: 'e-h-3-m', ...me, mark: score(9), at: '2026-07-14T11:05:00' },
    theirs: { editId: 'e-h-3-t', ...akinyi, mark: score(9), at: '2026-07-14T14:58:00' },
    proposals: [],
    resolution: { kind: 'auto' },
    resolvedAt: '2026-07-14T14:58:00',
  },
  {
    id: 'h-1',
    assessmentId: 'a7',
    studentId: 's3',
    criterionId: 'c1',
    student: 'Amina Osei',
    criterion: 'Comprehension',
    assessment: 'English CAT 1, Term 1',
    mine: { editId: 'e-h-1-m', ...me, mark: score(16), at: '2026-07-14T09:21:00' },
    theirs: { editId: 'e-h-1-t', ...akinyi, mark: score(16), at: '2026-07-14T11:03:00' },
    proposals: [],
    resolution: { kind: 'auto' },
    resolvedAt: '2026-07-14T11:03:00',
  },
]

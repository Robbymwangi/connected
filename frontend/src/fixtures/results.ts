/* Past assessment results on record, per student and assessment, as a total out of
   the rubric maximum. These stand in for finalized marks synced from the server;
   the live grid supplies results for assessments still being marked. */
export type ResultRecord = {
  studentId: string
  assessmentId: string
  total: number
}

/* Hand-written totals for the first eight 4W students, matching the export's
   profile data: English CAT 1 (a7, /60) and Maths CAT 1 (a8, /100) in Term 1, and
   Maths CAT 2 (a2, /100) in Term 2. */
const handWritten: ResultRecord[] = [
  { studentId: 's1', assessmentId: 'a7', total: 48 }, { studentId: 's1', assessmentId: 'a8', total: 76 }, { studentId: 's1', assessmentId: 'a2', total: 81 },
  { studentId: 's2', assessmentId: 'a7', total: 28 }, { studentId: 's2', assessmentId: 'a8', total: 55 }, { studentId: 's2', assessmentId: 'a2', total: 49 },
  { studentId: 's3', assessmentId: 'a7', total: 54 }, { studentId: 's3', assessmentId: 'a8', total: 88 }, { studentId: 's3', assessmentId: 'a2', total: 90 },
  { studentId: 's4', assessmentId: 'a7', total: 42 }, { studentId: 's4', assessmentId: 'a8', total: 63 }, { studentId: 's4', assessmentId: 'a2', total: 66 },
  { studentId: 's5', assessmentId: 'a7', total: 50 }, { studentId: 's5', assessmentId: 'a8', total: 70 }, { studentId: 's5', assessmentId: 'a2', total: 72 },
  { studentId: 's6', assessmentId: 'a7', total: 38 }, { studentId: 's6', assessmentId: 'a8', total: 58 }, { studentId: 's6', assessmentId: 'a2', total: 54 },
  { studentId: 's7', assessmentId: 'a7', total: 56 }, { studentId: 's7', assessmentId: 'a8', total: 92 }, { studentId: 's7', assessmentId: 'a2', total: 95 },
  { studentId: 's8', assessmentId: 'a7', total: 44 }, { studentId: 's8', assessmentId: 'a8', total: 66 }, { studentId: 's8', assessmentId: 'a2', total: 61 },
]

/* Generated totals. For 4W, the remaining students on the same three assessments;
   for the other streams, every assessment that has marks entered, up to the number
   the assessment record says were entered (a5 is part way through). English CAT 2
   (a1) is left out on purpose: its marks live in the grid, and five students there
   are not yet entered. */
type Gen = { assessmentId: string; max: number; studentIds: string[] }

function ids(prefix: string, from: number, to: number): string[] {
  return Array.from({ length: to - from + 1 }, (_, i) => `${prefix}${from + i}`)
}

const GENERATED: Gen[] = [
  { assessmentId: 'a7', max: 60, studentIds: ids('s', 9, 28) },
  { assessmentId: 'a8', max: 100, studentIds: ids('s', 9, 28) },
  { assessmentId: 'a2', max: 100, studentIds: ids('s', 9, 28) },
  { assessmentId: 'a3', max: 60, studentIds: ids('a', 1, 30) },
  { assessmentId: 'a5', max: 100, studentIds: ids('a', 1, 18) },
  { assessmentId: 'a6', max: 60, studentIds: ids('e', 1, 26) },
  { assessmentId: 'a9', max: 60, studentIds: ids('b', 1, 29) },
  { assessmentId: 'a10', max: 100, studentIds: ids('b', 1, 29) },
]

function generated(): ResultRecord[] {
  const out: ResultRecord[] = []
  GENERATED.forEach(({ assessmentId, max, studentIds }, j) => {
    studentIds.forEach((studentId, i) => {
      const share = 0.3 + (((i * 31 + j * 17 + studentId.charCodeAt(0)) % 23) / 22) * 0.65
      out.push({ studentId, assessmentId, total: Math.round(share * max) })
    })
  })
  return out
}

export const results: ResultRecord[] = [...handWritten, ...generated()]

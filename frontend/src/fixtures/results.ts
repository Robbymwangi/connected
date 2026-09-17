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

/* Generated totals for the remaining 4W students on the same three assessments. */
function generated(): ResultRecord[] {
  const out: ResultRecord[] = []
  const maxes: Array<[string, number]> = [['a7', 60], ['a8', 100], ['a2', 100]]
  for (let i = 9; i <= 28; i++) {
    maxes.forEach(([assessmentId, max], j) => {
      const share = 0.35 + (((i * 31 + j * 17) % 23) / 22) * 0.6
      out.push({ studentId: `s${i}`, assessmentId, total: Math.round(share * max) })
    })
  }
  return out
}

export const results: ResultRecord[] = [...handWritten, ...generated()]

/* Marking rubric per subject: the criteria a mark is broken into and their maxima. */
export type Criterion = {
  id: string
  name: string
  max: number
}

export const subjects = ['English', 'Maths', 'Science'] as const
export type Subject = (typeof subjects)[number]

const RUBRICS: Record<Subject, Criterion[]> = {
  English: [
    { id: 'c1', name: 'Comprehension', max: 20 },
    { id: 'c2', name: 'Written Expr.', max: 15 },
    { id: 'c3', name: 'Oral Fluency', max: 15 },
    { id: 'c4', name: 'Vocabulary', max: 10 },
  ],
  Maths: [
    { id: 'm1', name: 'Number Ops', max: 20 },
    { id: 'm2', name: 'Algebra', max: 20 },
    { id: 'm3', name: 'Geometry', max: 15 },
    { id: 'm4', name: 'Data Handling', max: 15 },
    { id: 'm5', name: 'Problem Solv.', max: 30 },
  ],
  Science: [
    { id: 'sc1', name: 'Knowledge', max: 25 },
    { id: 'sc2', name: 'Practical', max: 25 },
    { id: 'sc3', name: 'Analysis', max: 25 },
    { id: 'sc4', name: 'Comm./Report', max: 25 },
  ],
}

export function rubricFor(subject: Subject): Criterion[] {
  return RUBRICS[subject]
}

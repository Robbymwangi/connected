import type { Assessment } from '../fixtures/assessments'
import type { LocalRecord } from './localDatabase'
import type { SchoolDirectory } from './schoolDirectory'

export type AssessmentDraft = Omit<Assessment, 'id' | 'version'>

/* The row the screens show, and the fields of the create the outbox will send. The
   create fields exclude `status`: it is server-owned on a create, and `finalized`
   travels only as a finalize entry. */
export function createLocalAssessmentRecord(
  draft: AssessmentDraft,
  id: string,
  directory: SchoolDirectory,
): { record: LocalRecord; createFields: Record<string, unknown> } {
  const schoolClass = directory.classesForYear(draft.year).find((item) =>
    item.stream === draft.stream && item.subjects.includes(draft.subject),
  )
  const subjectId = Object.entries(directory.subjectNameById).find(([, name]) => name === draft.subject)?.[0]

  if (!schoolClass || !subjectId) {
    throw new Error('Assessment class or subject is not available in the local directory')
  }

  const termNumber: Record<string, number> = { 'Term 1': 1, 'Term 2': 2, 'Term 3': 3 }
  const wireTerm = termNumber[draft.term]
  if (wireTerm === undefined) throw new Error('Assessment term must be Term 1, Term 2, or Term 3')

  const createFields = {
    classId: schoolClass.id,
    subjectId,
    name: draft.name,
    term: wireTerm,
    year: draft.year,
    date: draft.date,
  }

  return {
    record: { id, version: 0, ...createFields, term: draft.term, status: 'scheduled', sync: 'pending' },
    createFields,
  }
}

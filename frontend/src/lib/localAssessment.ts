import type { Assessment } from '../fixtures/assessments'
import type { LocalRecord } from './localDatabase'
import type { SchoolDirectory } from './schoolDirectory'

export type AssessmentDraft = Omit<Assessment, 'id' | 'version'>

export function createLocalAssessmentRecord(
  draft: AssessmentDraft,
  id: string,
  directory: SchoolDirectory,
): LocalRecord {
  const schoolClass = directory.classesForYear(draft.year).find((item) =>
    item.stream === draft.stream && item.subjects.includes(draft.subject),
  )
  const subjectId = Object.entries(directory.subjectNameById).find(([, name]) => name === draft.subject)?.[0]

  if (!schoolClass || !subjectId) {
    throw new Error('Assessment class or subject is not available in the local directory')
  }

  return {
    id,
    version: 0,
    classId: schoolClass.id,
    subjectId,
    name: draft.name,
    term: draft.term,
    year: draft.year,
    date: draft.date,
    status: 'scheduled',
    sync: 'pending',
    pendingBaseVersion: 0,
    pendingFields: {
      classId: schoolClass.id,
      subjectId,
      name: draft.name,
      term: draft.term,
      year: draft.year,
      date: draft.date,
    },
  }
}
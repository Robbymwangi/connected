import type { Subject } from './rubrics'

/* Where an assessment is in its life. Statuses are ordered: an assessment only
   moves forward. */
export type LifecycleStatus =
  | 'scheduled'
  | 'in-progress'
  | 'complete'
  | 'finalized'
  | 'reports-generated'

/* Sync state of the record on this device. Distinct from connectivity. */
export type SyncState = 'synced' | 'pending' | 'conflict'

/* The school year's terms. */
export const TERMS = ['Term 1', 'Term 2', 'Term 3'] as const

export type Assessment = {
  id: string
  subject: Subject
  stream: string
  name: string
  term: string
  year: number
  /* The day the assessment is sat, ISO yyyy-mm-dd. */
  date: string
  /* Server-assigned, incremented on each accepted write; 0 means never
     acknowledged by the server. See docs/adr/0001-record-versioning.md. */
  version: number
  entered: number
  total: number
  status: LifecycleStatus
  sync: SyncState
}

export const assessments: Assessment[] = [
  { id: 'a1', subject: 'English', stream: '4W', name: 'CAT 2', term: 'Term 2', year: 2025, date: '2025-08-18', version: 7, entered: 23, total: 28, status: 'in-progress', sync: 'conflict' },
  { id: 'a2', subject: 'Maths', stream: '4W', name: 'CAT 2', term: 'Term 2', year: 2025, date: '2025-08-19', version: 5, entered: 28, total: 28, status: 'complete', sync: 'pending' },
  { id: 'a3', subject: 'English', stream: '5A', name: 'CAT 1', term: 'Term 2', year: 2025, date: '2025-07-14', version: 4, entered: 30, total: 30, status: 'finalized', sync: 'synced' },
  { id: 'a4', subject: 'Science', stream: '4W', name: 'Practical 1', term: 'Term 2', year: 2025, date: '2025-09-08', version: 1, entered: 0, total: 28, status: 'scheduled', sync: 'synced' },
  { id: 'a5', subject: 'Maths', stream: '5A', name: 'CAT 2', term: 'Term 2', year: 2025, date: '2025-08-25', version: 3, entered: 18, total: 30, status: 'in-progress', sync: 'pending' },
  { id: 'a6', subject: 'English', stream: '4E', name: 'End of Term', term: 'Term 1', year: 2025, date: '2025-04-07', version: 6, entered: 26, total: 26, status: 'reports-generated', sync: 'synced' },
  { id: 'a7', subject: 'English', stream: '4W', name: 'CAT 1', term: 'Term 1', year: 2025, date: '2025-03-10', version: 6, entered: 28, total: 28, status: 'reports-generated', sync: 'synced' },
  { id: 'a8', subject: 'Maths', stream: '4W', name: 'CAT 1', term: 'Term 1', year: 2025, date: '2025-03-11', version: 6, entered: 28, total: 28, status: 'reports-generated', sync: 'synced' },
  { id: 'a9', subject: 'English', stream: '5B', name: 'End of Term', term: 'Term 2', year: 2024, date: '2024-08-12', version: 4, entered: 29, total: 29, status: 'reports-generated', sync: 'synced' },
  { id: 'a10', subject: 'Maths', stream: '5B', name: 'End of Term', term: 'Term 2', year: 2024, date: '2024-08-13', version: 4, entered: 29, total: 29, status: 'reports-generated', sync: 'synced' },
]

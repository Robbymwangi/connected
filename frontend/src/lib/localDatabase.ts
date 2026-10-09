import Dexie, { type EntityTable } from 'dexie'
import { migrateMarkers } from './markers'
import type { OutboxEntry } from './outbox'
import type { Session } from './session'

export const SYNC_TABLES = [
  'assessments',
  'class_subjects',
  'comments',
  'conflicts',
  'criteria',
  'enrolments',
  'marks',
  'notifications',
  'reports',
  'results',
  'classes',
  'students',
  'subjects',
  'subject_moderations',
  'teacher_assignments',
  'users',
] as const

export type SyncTableName = (typeof SYNC_TABLES)[number]

export type LocalRecord = Record<string, unknown> & {
  id: string
  version: number
  deletedAt?: string | null
}

export type MetadataRecord = { key: string; value: unknown }
export type SessionRecord = { key: 'current'; session: Session }

const DATABASE_NAME = 'connected'
const USER_DATABASE_PREFIX = 'connected-user-'
const userDatabases = new Map<string, LocalDatabase>()

export class LocalDatabase extends Dexie {
  assessments!: EntityTable<LocalRecord, 'id'>
  class_subjects!: EntityTable<LocalRecord, 'id'>
  comments!: EntityTable<LocalRecord, 'id'>
  conflicts!: EntityTable<LocalRecord, 'id'>
  criteria!: EntityTable<LocalRecord, 'id'>
  enrolments!: EntityTable<LocalRecord, 'id'>
  marks!: EntityTable<LocalRecord, 'id'>
  notifications!: EntityTable<LocalRecord, 'id'>
  reports!: EntityTable<LocalRecord, 'id'>
  results!: EntityTable<LocalRecord, 'id'>
  classes!: EntityTable<LocalRecord, 'id'>
  students!: EntityTable<LocalRecord, 'id'>
  subjects!: EntityTable<LocalRecord, 'id'>
  subject_moderations!: EntityTable<LocalRecord, 'id'>
  teacher_assignments!: EntityTable<LocalRecord, 'id'>
  users!: EntityTable<LocalRecord, 'id'>
  metadata!: EntityTable<MetadataRecord, 'key'>
  sessions!: EntityTable<SessionRecord, 'key'>
  outbox!: EntityTable<OutboxEntry, 'seq'>

  constructor(name = DATABASE_NAME) {
    super(name)

    this.version(1).stores({
      assessments: '&id, classId, subjectId, createdBy',
      class_subjects: '&id, classId, subjectId',
      comments: '&id, assessmentId, studentId, authorId',
      conflicts: '&id, markId, resolvedAt',
      criteria: '&id, subjectId',
      enrolments: '&id, studentId, classId, year',
      marks: '&id, assessmentId, studentId, criterionId, lastEditedBy',
      notifications: '&id, userId, assessmentId, unread',
      reports: '&id, assessmentId, studentId',
      results: '&id, assessmentId, studentId',
      classes: '&id, grade, stream, classTeacherId',
      students: '&id, name',
      subjects: '&id, name',
      subject_moderations: '&id, userId, subjectId',
      teacher_assignments: '&id, userId, classId, subjectId',
      users: '&id, email',
      metadata: '&key',
      sessions: '&key',
    })

    /* The outbox of unsettled local writes (build-plan 3.4). */
    this.version(2).stores({
      outbox: '++seq, &id, [table+recordId], state',
    })

    /* Pending markers on records move into the outbox (ADR 0011, decision 7). This
       ships with the writers that stop producing them. The upgrade awaits only
       Dexie operations: anything else would let the transaction auto-commit. */
    this.version(3).stores({}).upgrade(async (transaction) => {
      const { entries, records } = migrateMarkers({
        assessments: await transaction.table('assessments').toArray(),
        marks: await transaction.table('marks').toArray(),
      }, new Date().toISOString())

      await transaction.table('outbox').bulkAdd(entries)
      await transaction.table('assessments').bulkPut(records.assessments)
      await transaction.table('marks').bulkPut(records.marks)
    })
  }
}

export const localDatabase = new LocalDatabase()

export function localDatabaseFor(userId: string): LocalDatabase {
  let database = userDatabases.get(userId)
  if (!database) {
    database = new LocalDatabase(`${USER_DATABASE_PREFIX}${userId}`)
    userDatabases.set(userId, database)
  }
  return database
}
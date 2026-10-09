import { wireMarkFields, type GridCellChange } from './localMarks'
import type { LocalDatabase, LocalRecord } from './localDatabase'
import { enqueue, entriesForRecord, type OutboxEntry } from './outbox'

/* Every local write is one transaction over the record and the outbox, so the two
   commit or roll back together (ADR 0011, decision 5). Nothing inside a transaction
   here may await anything but Dexie: a fetch, a timer, or crypto.subtle lets the
   transaction auto-commit and the next Dexie call fails. Mark ids, which need
   crypto.subtle, are computed before the call. */

export type NewAssessment = { record: LocalRecord; createFields: Record<string, unknown> }
export type MarkCellWrite = GridCellChange & { id: string }

const OPEN_STATES = ['queued', 'sent', 'conflict']

function isOpen(entry: OutboxEntry): boolean {
  return entry.kind !== 'command' && OPEN_STATES.includes(entry.state)
}

function mapOf(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? { ...value as Record<string, unknown> } : {}
}

/* The server's value for each field this write protects, with the version it was
   read at, kept so a refused entry can be reverted and a pull can land its value
   without losing it. A field is copied only while the record still holds the server's
   value for it: not once an open entry covers it, and not on a record the server has
   not seen. The version tells a settling push which of a seeded value and a later
   pulled one it is looking at. */
function shadowFor(current: LocalRecord, fields: string[], entries: OutboxEntry[]): { serverShadow?: Record<string, unknown>; serverShadowAt?: Record<string, unknown> } {
  const shadow = mapOf(current.serverShadow)
  const shadowAt = mapOf(current.serverShadowAt)
  if (current.version === 0) return Object.keys(shadow).length ? { serverShadow: shadow, serverShadowAt: shadowAt } : {}

  const covered = new Set(entries.filter(isOpen).flatMap((entry) => Object.keys(entry.fields)))
  for (const field of fields) {
    if (field in shadow || covered.has(field)) continue
    shadow[field] = current[field] ?? null
    shadowAt[field] = current.version
  }
  return Object.keys(shadow).length ? { serverShadow: shadow, serverShadowAt: shadowAt } : {}
}

export function createAssessments(database: LocalDatabase, items: NewAssessment[], at: string): Promise<void> {
  return database.transaction('rw', [database.assessments, database.outbox], async () => {
    await database.assessments.bulkAdd(items.map((item) => item.record))
    for (const { record, createFields } of items) {
      await enqueue(database, { table: 'assessments', recordId: record.id, kind: 'patch', baseVersion: 0, fields: createFields, at })
    }
  })
}

export function finalizeAssessmentRecord(database: LocalDatabase, id: string, userId: string, at: string): Promise<boolean> {
  return database.transaction('rw', [database.assessments, database.outbox], async () => {
    const current = await database.assessments.get(id)
    if (!current || current.deletedAt != null) return false
    if (current.status === 'finalized' || current.status === 'reports-generated') return false

    const fields = { status: 'finalized', finalizedBy: userId, finalizedAt: at }
    const entries = await entriesForRecord(database, 'assessments', id)
    const shadow = shadowFor(current, Object.keys(fields), entries)

    await database.assessments.put({ ...current, ...fields, sync: 'pending', ...shadow })
    await enqueue(database, { table: 'assessments', recordId: id, kind: 'finalize', baseVersion: current.version, fields, at })
    return true
  })
}

export function writeMarkCells(
  database: LocalDatabase,
  assessmentId: string,
  changes: MarkCellWrite[],
  author: string,
  at: string,
): Promise<GridCellChange[]> {
  return database.transaction('rw', [database.marks, database.assessments, database.outbox], async () => {
    const assessment = await database.assessments.get(assessmentId)
    if (!assessment || assessment.deletedAt != null) throw new Error('Assessment is not available on this device')
    if (assessment.status === 'finalized' || assessment.status === 'reports-generated') {
      throw new Error('A finalized assessment takes no further marks')
    }

    const saved: GridCellChange[] = []
    for (const change of changes) {
      const current = await database.marks.get(change.id)
      const entries = await entriesForRecord(database, 'marks', change.id)

      /* The version the teacher saw, capped at what is stored, so a pull landing
         between the render and this write cannot quietly rebase the edit. */
      let base = 0
      if (current) {
        const seen = change.cell.baseVersion ?? current.version
        if (!Number.isSafeInteger(seen) || seen < 0) throw new Error('A mark edit needs a valid base version')
        base = Math.min(seen, current.version)
      }

      /* Identity fields make a create. A follow-up behind a sent or conflicted entry
         is not one, and the allowlist refuses a change to a cell's identity. */
      const blocked = entries.some((entry) => isOpen(entry) && entry.state !== 'queued')
      const identity = base === 0 && !blocked
        ? { assessmentId, studentId: change.studentId, criterionId: change.criterionId }
        : {}
      const fields = { ...identity, ...wireMarkFields(change.cell.mark) }

      const shadow = current ? shadowFor(current, Object.keys(wireMarkFields(change.cell.mark)), entries) : {}
      const record: LocalRecord = {
        ...(current ?? {}),
        id: change.id,
        version: current?.version ?? 0,
        assessmentId,
        studentId: change.studentId,
        criterionId: change.criterionId,
        ...wireMarkFields(change.cell.mark),
        sync: 'pending',
        localAuthor: author,
        ...shadow,
      }

      await database.marks.put(record)
      await enqueue(database, { table: 'marks', recordId: change.id, kind: 'patch', baseVersion: base, fields, at })
      saved.push({
        studentId: change.studentId,
        criterionId: change.criterionId,
        cell: { ...change.cell, sync: 'local', baseVersion: record.version, author },
      })
    }
    return saved
  })
}

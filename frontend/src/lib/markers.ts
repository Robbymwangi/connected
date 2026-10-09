import type { LocalRecord } from './localDatabase'
import type { OutboxEntry } from './outbox'

/* Converts the pending markers of schema v1 and v2 (`pendingBaseVersion`,
   `pendingFields`, `pendingFinalize`) into outbox entries, once, in the v3 upgrade
   (ADR 0011, decision 7). Pure: the upgrade reads the rows, calls this, and writes
   the result, so it awaits nothing but Dexie. */

const FINALIZE_FIELDS = ['status', 'finalizedBy', 'finalizedAt'] as const
const ASSESSMENT_CREATE_FIELDS = ['classId', 'subjectId', 'name', 'term', 'year', 'date'] as const
const MARK_IDENTITY_FIELDS = ['assessmentId', 'studentId', 'criterionId'] as const
const MARKER_KEYS = ['pendingBaseVersion', 'pendingFields', 'pendingFinalize'] as const

type Rows = { assessments: LocalRecord[]; marks: LocalRecord[] }
type Draft = Omit<OutboxEntry, 'seq' | 'id' | 'state' | 'at'>

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function hasMarkers(record: LocalRecord): boolean {
  return record.sync === 'pending' || MARKER_KEYS.some((key) => key in record)
}

function pick(record: LocalRecord, keys: readonly string[]): Record<string, unknown> {
  return Object.fromEntries(keys.filter((key) => record[key] !== undefined).map((key) => [key, record[key]]))
}

function without(fields: Record<string, unknown>, keys: readonly string[]): Record<string, unknown> {
  return Object.fromEntries(Object.entries(fields).filter(([key]) => !keys.includes(key)))
}

/* The version the teacher saw. A pull may have advanced `version` over a pending
   record since, so the marker's own base wins. */
function baseOf(record: LocalRecord): number {
  const base = record.pendingBaseVersion
  if (Number.isSafeInteger(base) && (base as number) >= 0) return base as number
  return Number.isSafeInteger(record.version) ? record.version : 0
}

function finalizeFields(record: LocalRecord, pending: Record<string, unknown>, extra: Record<string, unknown>) {
  const finalizedBy = extra.finalizedBy ?? pending.finalizedBy ?? record.finalizedBy
  const finalizedAt = extra.finalizedAt ?? pending.finalizedAt ?? record.finalizedAt
  return typeof finalizedBy === 'string' && typeof finalizedAt === 'string'
    ? { status: 'finalized', finalizedBy, finalizedAt }
    : null
}

function assessmentDrafts(record: LocalRecord): { patches: Draft[]; finalizes: Draft[] } {
  const base = baseOf(record)
  const pending = isObject(record.pendingFields) ? record.pendingFields : {}
  const pendingFinalize = isObject(record.pendingFinalize) ? record.pendingFinalize : null
  let fields = without(pending, FINALIZE_FIELDS)

  if (Object.keys(fields).length === 0 && base === 0) fields = pick(record, ASSESSMENT_CREATE_FIELDS)

  const patches: Draft[] = Object.keys(fields).length
    ? [{ table: 'assessments', recordId: record.id, kind: 'patch', baseVersion: base, fields }]
    : []

  const wantsFinalize = pending.status === 'finalized' || pendingFinalize !== null
  const finalize = wantsFinalize ? finalizeFields(record, pending, pendingFinalize ?? {}) : null
  const finalizes: Draft[] = finalize
    ? [{
        table: 'assessments', recordId: record.id, kind: 'finalize',
        baseVersion: patches.length ? null : base, fields: finalize,
      }]
    : []

  return { patches, finalizes }
}

function markDraft(record: LocalRecord): Draft | null {
  const base = baseOf(record)
  let fields = isObject(record.pendingFields) ? { ...record.pendingFields } : {}

  if (Object.keys(fields).length === 0) {
    if (base > 0) return null
    fields = pick(record, ['markKind', 'score'])
  }
  if (base === 0) fields = { ...pick(record, MARK_IDENTITY_FIELDS), ...fields }

  return Object.keys(fields).length
    ? { table: 'marks', recordId: record.id, kind: 'patch', baseVersion: base, fields }
    : null
}

function cleaned(record: LocalRecord, pending: boolean): LocalRecord {
  const rest: LocalRecord = { ...record }
  for (const key of MARKER_KEYS) delete rest[key]
  if (pending) rest.sync = 'pending'
  else delete rest.sync
  return rest
}

function byId<T extends { id: string }>(a: T, b: T): number {
  return a.id < b.id ? -1 : a.id > b.id ? 1 : 0
}

export function migrateMarkers(rows: Rows, at: string): { entries: OutboxEntry[]; records: Rows } {
  const patches: Draft[] = []
  const markDrafts: Draft[] = []
  const finalizes: Draft[] = []
  const records: Rows = { assessments: [], marks: [] }

  for (const record of rows.assessments.filter(hasMarkers).sort(byId)) {
    const drafts = assessmentDrafts(record)
    patches.push(...drafts.patches)
    finalizes.push(...drafts.finalizes)
    records.assessments.push(cleaned(record, drafts.patches.length + drafts.finalizes.length > 0))
  }

  for (const record of rows.marks.filter(hasMarkers).sort(byId)) {
    const draft = markDraft(record)
    if (draft) markDrafts.push(draft)
    records.marks.push(cleaned(record, draft !== null))
  }

  const entries = [...patches, ...markDrafts, ...finalizes].map((draft): OutboxEntry => ({
    ...draft, id: crypto.randomUUID(), at, state: 'queued',
  }))
  return { entries, records }
}

import { describe, expect, it } from 'vitest'
import type { LocalRecord } from './localDatabase'
import { migrateMarkers } from './markers'

const AT = '2026-10-09T08:00:00.000Z'
const CREATE = { classId: 'c1', subjectId: 'sub1', name: 'CAT 1', term: 'Term 1', year: 2025, date: '2025-05-12' }

const assessments: LocalRecord[] = [
  { id: 'a1', version: 0, ...CREATE, status: 'scheduled', sync: 'pending', pendingBaseVersion: 0, pendingFields: CREATE },
  {
    id: 'a2', version: 3, name: 'Renamed', status: 'finalized', finalizedBy: 'u1', finalizedAt: AT, sync: 'pending',
    pendingBaseVersion: 3,
    pendingFields: { name: 'Renamed', status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
  },
  {
    id: 'a3', version: 5, status: 'finalized', finalizedBy: 'u1', finalizedAt: AT, sync: 'pending', pendingBaseVersion: 5,
    pendingFields: { status: 'finalized', finalizedBy: 'u1', finalizedAt: AT },
  },
  {
    id: 'a4', version: 0, ...CREATE, status: 'finalized', finalizedBy: 'u1', finalizedAt: AT, sync: 'pending',
    pendingBaseVersion: 0, pendingFields: CREATE, pendingFinalize: { finalizedBy: 'u1', finalizedAt: AT },
  },
  { id: 'a5', version: 2, ...CREATE, status: 'scheduled', sync: 'pending', pendingBaseVersion: 2, pendingFields: {} },
  { id: 'a6', version: 0, ...CREATE, status: 'scheduled', sync: 'pending' },
]

const marks: LocalRecord[] = [
  {
    id: 'm1', version: 0, assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 9,
    sync: 'pending', pendingBaseVersion: 0,
    pendingFields: { assessmentId: 'a1', studentId: 's1', criterionId: 'k1', markKind: 'score', score: 9 },
  },
  {
    id: 'm2', version: 4, assessmentId: 'a2', studentId: 's1', criterionId: 'k1', markKind: 'absent', score: null,
    sync: 'pending', pendingBaseVersion: 3, pendingFields: { markKind: 'absent', score: null }, localAuthor: 'Teacher',
  },
  {
    id: 'm3', version: 2, assessmentId: 'a2', studentId: 's2', criterionId: 'k1', markKind: 'score', score: 5,
    sync: 'pending', pendingBaseVersion: 0, pendingFields: { markKind: 'score', score: 5 },
  },
  { id: 'm4', version: 3, assessmentId: 'a2', studentId: 's3', criterionId: 'k1', markKind: 'score', score: 7, lastEditedBy: 'u2' },
]

describe('migrateMarkers', () => {
  const result = migrateMarkers({ assessments, marks }, AT)
  const summary = result.entries.map((entry) => [entry.table, entry.recordId, entry.kind, entry.baseVersion])

  it('orders assessment creates and patches, then marks, then finalizes', () => {
    expect(summary).toEqual([
      ['assessments', 'a1', 'patch', 0],
      ['assessments', 'a2', 'patch', 3],
      ['assessments', 'a4', 'patch', 0],
      ['assessments', 'a6', 'patch', 0],
      ['marks', 'm1', 'patch', 0],
      ['marks', 'm2', 'patch', 3],
      ['marks', 'm3', 'patch', 0],
      ['assessments', 'a2', 'finalize', null],
      ['assessments', 'a3', 'finalize', 5],
      ['assessments', 'a4', 'finalize', null],
    ])
    expect(result.entries.every((entry) => entry.state === 'queued' && entry.at === AT)).toBe(true)
    expect(new Set(result.entries.map((entry) => entry.id)).size).toBe(result.entries.length)
  })

  it('splits a folded finalize into a patch and a trailing finalize', () => {
    const patch = result.entries.find((entry) => entry.recordId === 'a2' && entry.kind === 'patch')
    const finalize = result.entries.find((entry) => entry.recordId === 'a2' && entry.kind === 'finalize')

    expect(patch?.fields).toEqual({ name: 'Renamed' })
    expect(finalize?.fields).toEqual({ status: 'finalized', finalizedBy: 'u1', finalizedAt: AT })
  })

  it('makes a lone finalize one entry with its own base and no empty patch', () => {
    const entries = result.entries.filter((entry) => entry.recordId === 'a3')

    expect(entries.map((entry) => entry.kind)).toEqual(['finalize'])
    expect(entries[0].baseVersion).toBe(5)
  })

  it('keeps an uncreated assessment finalize behind its create', () => {
    const finalize = result.entries.find((entry) => entry.recordId === 'a4' && entry.kind === 'finalize')

    expect(finalize?.fields).toEqual({ status: 'finalized', finalizedBy: 'u1', finalizedAt: AT })
    expect(result.entries.find((entry) => entry.recordId === 'a4' && entry.kind === 'patch')?.fields).toEqual(CREATE)
  })

  it('rebuilds the create of an unsent assessment that lost its fields, and none for a synced one', () => {
    expect(result.entries.find((entry) => entry.recordId === 'a6')?.fields).toEqual(CREATE)
    expect(result.entries.some((entry) => entry.recordId === 'a5')).toBe(false)
  })

  it('keeps the base the teacher saw when a pull has since advanced the version, and adds identity at base 0', () => {
    const m3 = result.entries.find((entry) => entry.recordId === 'm3')
    const m2 = result.entries.find((entry) => entry.recordId === 'm2')

    expect(m3?.baseVersion).toBe(0)
    expect(m3?.fields).toEqual({
      assessmentId: 'a2', studentId: 's2', criterionId: 'k1', markKind: 'score', score: 5,
    })
    expect(m2?.fields).toEqual({ markKind: 'absent', score: null })
  })

  it('removes every marker and sets the pending flag only where an entry exists', () => {
    const all = [...result.records.assessments, ...result.records.marks]

    for (const record of all) {
      expect(record).not.toHaveProperty('pendingFields')
      expect(record).not.toHaveProperty('pendingBaseVersion')
      expect(record).not.toHaveProperty('pendingFinalize')
    }
    const flag = (id: string) => all.find((record) => record.id === id)?.sync
    expect(flag('a1')).toBe('pending')
    expect(flag('a5')).toBeUndefined()
    expect(result.records.marks.map((record) => record.id)).toEqual(['m1', 'm2', 'm3'])
    expect(result.records.marks.find((record) => record.id === 'm2')?.localAuthor).toBe('Teacher')
  })

  it('leaves rows without markers out of the result', () => {
    expect(result.records.marks.some((record) => record.id === 'm4')).toBe(false)
  })

  it('emits nothing for an empty input', () => {
    expect(migrateMarkers({ assessments: [], marks: [] }, AT)).toEqual({
      entries: [], records: { assessments: [], marks: [] },
    })
  })
})

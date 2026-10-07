import { describe, expect, it } from 'vitest'
import { LocalDatabase, SYNC_TABLES } from './localDatabase'

describe('LocalDatabase schema', () => {
  it('contains every synchronisable table plus local metadata and session tables', () => {
    const database = new LocalDatabase(`connected-test-${crypto.randomUUID()}`)

    expect(database.tables.map((table) => table.name).sort()).toEqual(
      [...SYNC_TABLES, 'metadata', 'sessions'].sort(),
    )
    for (const tableName of SYNC_TABLES) {
      expect(database.table(tableName).schema.primKey.keyPath).toBe('id')
    }
    expect(database.metadata.schema.primKey.keyPath).toBe('key')
    expect(database.sessions.schema.primKey.keyPath).toBe('key')
    expect(database.marks.schema.indexes.map((index) => index.name)).toEqual([
      'assessmentId',
      'studentId',
      'criterionId',
      'lastEditedBy',
    ])

    database.close()
  })
})
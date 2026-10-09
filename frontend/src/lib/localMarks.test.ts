import { describe, expect, it } from 'vitest'
import type { Grid } from '../fixtures/marks'
import { ABSENT, EMPTY, score } from './grading'
import { changedGridCells, emptyGrid, gridFromMarkRows, mergePendingMarkCells, pendingMarkRecord } from './localMarks'

describe('local mark persistence helpers', () => {
  it('creates empty rows for the requested students and criteria', () => {
    expect(emptyGrid(['s1'], ['c1', 'c2'])).toEqual({
      s1: { c1: { mark: EMPTY, sync: 'synced' }, c2: { mark: EMPTY, sync: 'synced' } },
    })
  })

  it('rebuilds displayed cells with server base versions and local authors', () => {
    const grid: Grid = { student: { criterion: { mark: EMPTY, sync: 'synced' } } }

    expect(gridFromMarkRows([{
      id: 'mark-1', version: 4, assessmentId: 'assessment', studentId: 'student', criterionId: 'criterion',
      markKind: 'score', score: 13, lastEditedBy: 'server-user',
    }], grid)).toEqual({
      student: { criterion: { mark: score(13), sync: 'synced', baseVersion: 4 } },
    })
  })

  it('finds only changed mark values, including absent and clearing a score', () => {
    const before: Grid = { student: { score: { mark: score(7), sync: 'synced' }, absent: { mark: EMPTY, sync: 'synced' } } }
    const after: Grid = { student: { score: { mark: ABSENT, sync: 'local' }, absent: { mark: EMPTY, sync: 'local' } } }

    expect(changedGridCells(before, after).map(({ criterionId, cell }) => [criterionId, cell.mark])).toEqual([
      ['score', ABSENT],
    ])
  })

  it('keeps a persisted local value while retaining the displayed base version', () => {
    const displayed: Grid = { student: { criterion: { mark: score(7), sync: 'synced', baseVersion: 2 } } }
    const persisted: Grid = { student: { criterion: { mark: score(9), sync: 'local', baseVersion: 2 } } }

    expect(mergePendingMarkCells(displayed, persisted)).toEqual({
      student: { criterion: { mark: score(9), sync: 'local', baseVersion: 2 } },
    })
  })

  it('keeps the earliest base and unions edited fields while refreshing unrelated server fields', () => {
    const current = {
      id: 'mark-1', version: 4, assessmentId: 'assessment', studentId: 'student', criterionId: 'criterion',
      markKind: 'score', score: 12, lastEditedBy: 'server-user', pendingBaseVersion: 3,
      pendingFields: { markKind: 'score', score: 12 }, sync: 'pending',
    }
    const record = pendingMarkRecord(current, {
      assessmentId: 'assessment', studentId: 'student', criterionId: 'criterion',
      cell: { mark: ABSENT, sync: 'local', baseVersion: 3 },
    }, 'mark-1', 'Local Teacher')

    expect(record).toMatchObject({
      id: 'mark-1', version: 4, lastEditedBy: 'server-user', localAuthor: 'Local Teacher',
      pendingBaseVersion: 3, pendingFields: { markKind: 'absent', score: null },
      markKind: 'absent', score: null, sync: 'pending',
    })
  })

  it('creates a complete version-0 mark patch with identity fields', () => {
    const record = pendingMarkRecord(undefined, {
      assessmentId: 'assessment', studentId: 'student', criterionId: 'criterion',
      cell: { mark: score(9), sync: 'local', baseVersion: 0 },
    }, 'mark-1', 'Local Teacher')

    expect(record).toMatchObject({
      id: 'mark-1', version: 0, pendingBaseVersion: 0, localAuthor: 'Local Teacher', sync: 'pending',
      pendingFields: {
        assessmentId: 'assessment', studentId: 'student', criterionId: 'criterion', markKind: 'score', score: 9,
      },
    })
  })
})
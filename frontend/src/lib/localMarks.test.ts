import { describe, expect, it } from 'vitest'
import type { Grid } from '../fixtures/marks'
import { ABSENT, EMPTY, score } from './grading'
import { changedGridCells, emptyGrid, gridFromMarkRows, mergePendingMarkCells, wireMarkFields } from './localMarks'

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

  it('shows a pending record, or one the server has not seen, as local with the stored version as its base', () => {
    const grid: Grid = { student: { a: { mark: EMPTY, sync: 'synced' }, b: { mark: EMPTY, sync: 'synced' } } }

    expect(gridFromMarkRows([
      { id: 'm1', version: 4, assessmentId: 'x', studentId: 'student', criterionId: 'a', markKind: 'score', score: 9, sync: 'pending', localAuthor: 'Me' },
      { id: 'm2', version: 0, assessmentId: 'x', studentId: 'student', criterionId: 'b', markKind: 'absent', score: null },
    ], grid)).toEqual({
      student: {
        a: { mark: score(9), sync: 'local', baseVersion: 4, author: 'Me' },
        b: { mark: ABSENT, sync: 'local', baseVersion: 0 },
      },
    })
  })

  it('ignores the removed pending markers', () => {
    const grid: Grid = { student: { a: { mark: EMPTY, sync: 'synced' } } }

    expect(gridFromMarkRows([{
      id: 'm1', version: 4, assessmentId: 'x', studentId: 'student', criterionId: 'a', markKind: 'score', score: 9,
      pendingBaseVersion: 2, pendingFields: { score: 9 },
    }], grid).student.a).toEqual({ mark: score(9), sync: 'synced', baseVersion: 4 })
  })

  it('names the wire fields of a mark, with a null score unless it is a score', () => {
    expect(wireMarkFields(score(9))).toEqual({ markKind: 'score', score: 9 })
    expect(wireMarkFields(ABSENT)).toEqual({ markKind: 'absent', score: null })
    expect(wireMarkFields(EMPTY)).toEqual({ markKind: 'empty', score: null })
  })
})

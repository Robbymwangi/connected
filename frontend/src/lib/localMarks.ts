import type { Grid, GridCell } from '../fixtures/marks'
import type { LocalRecord } from './localDatabase'
import { ABSENT, EMPTY, score, type Mark } from './grading'

export type GridCellChange = {
  studentId: string
  criterionId: string
  cell: GridCell
}

export function emptyGrid(studentIds: string[], criterionIds: string[]): Grid {
  const grid: Grid = {}
  for (const studentId of studentIds) {
    grid[studentId] = {}
    for (const criterionId of criterionIds) {
      grid[studentId][criterionId] = { mark: EMPTY, sync: 'synced' }
    }
  }
  return grid
}

function integer(value: unknown): number | null {
  return Number.isSafeInteger(value) ? value as number : null
}

function markFromRecord(record: LocalRecord): Mark | null {
  if (record.markKind === 'empty') return EMPTY
  if (record.markKind === 'absent') return ABSENT
  if (record.markKind === 'score' && integer(record.score) !== null && (record.score as number) >= 0) {
    return score(record.score as number)
  }
  return null
}

export function gridFromMarkRows(rows: LocalRecord[], fallback: Grid, names: ReadonlyMap<string, string> = new Map()): Grid {
  const grid: Grid = Object.fromEntries(Object.entries(fallback).map(([studentId, row]) => [studentId, { ...row }]))

  for (const row of rows) {
    if (row.deletedAt != null) continue
    const studentId = typeof row.studentId === 'string' ? row.studentId : ''
    const criterionId = typeof row.criterionId === 'string' ? row.criterionId : ''
    const mark = markFromRecord(row)
    if (!studentId || !criterionId || !mark) continue

    const version = integer(row.version) ?? 0
    const localAuthor = typeof row.localAuthor === 'string' ? row.localAuthor : ''
    const lastEditedBy = typeof row.lastEditedBy === 'string' ? row.lastEditedBy : ''
    grid[studentId] ??= {}
    grid[studentId][criterionId] = {
      mark,
      sync: row.sync === 'pending' || version === 0 ? 'local' : 'synced',
      baseVersion: version,
      ...((localAuthor || names.get(lastEditedBy)) ? { author: localAuthor || names.get(lastEditedBy) } : {}),
    }
  }

  return grid
}

export function mergePendingMarkCells(displayed: Grid, persisted: Grid): Grid {
  const grid: Grid = Object.fromEntries(Object.entries(displayed).map(([studentId, row]) => [studentId, { ...row }]))
  for (const [studentId, row] of Object.entries(persisted)) {
    for (const [criterionId, cell] of Object.entries(row)) {
      if (cell.sync !== 'local') continue
      grid[studentId] ??= {}
      grid[studentId][criterionId] = cell
    }
  }
  return grid
}

function sameMark(left: Mark | undefined, right: Mark | undefined): boolean {
  if (!left || !right || left.kind !== right.kind) return left === right
  return left.kind !== 'score' || (right.kind === 'score' && left.value === right.value)
}

export function changedGridCells(before: Grid, after: Grid): GridCellChange[] {
  const changes: GridCellChange[] = []
  for (const [studentId, row] of Object.entries(after)) {
    for (const [criterionId, cell] of Object.entries(row)) {
      if (!sameMark(before[studentId]?.[criterionId]?.mark, cell.mark)) {
        changes.push({ studentId, criterionId, cell })
      }
    }
  }
  return changes
}

export function wireMarkFields(mark: Mark): Record<string, unknown> {
  return {
    markKind: mark.kind,
    score: mark.kind === 'score' ? mark.value : null,
  }
}

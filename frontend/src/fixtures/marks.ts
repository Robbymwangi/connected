import { ABSENT, EMPTY, score, type Mark } from '../lib/grading'

/* Sync state of one cell on this device. Separate from the mark itself. Conflicts
   are not a cell state: they are records in fixtures/conflicts.ts, and the grid
   looks its cells up there, so one source says whether a cell is contested. */
export type CellSync = 'synced' | 'local'

export type GridCell = {
  mark: Mark
  sync: CellSync
  author?: string
}

/* studentId -> criterionId -> cell */
export type Grid = Record<string, Record<string, GridCell>>

const me = 'John Doe'
const synced = (value: number): GridCell => ({ mark: score(value), sync: 'synced', author: me })
const local = (value: number): GridCell => ({ mark: score(value), sync: 'local', author: me })

/* English CAT 2, Term 2, Grade 4W (assessment a1). Criteria c1 /20, c2 /15, c3 /15,
   c4 /10. Deterministic, so a screen looks the same on every mount. Two cells (s1/c3 and
   s2/c2) hold this device's side of the conflicts in fixtures/conflicts.ts; two are
   local and unsynced; one student has not been entered at all; one was absent for
   the oral criterion. */
export const marksByAssessment: Record<string, Grid> = {
  a1: {
    s1: { c1: synced(16), c2: synced(12), c3: synced(14), c4: synced(8) },
    s2: { c1: synced(9), c2: synced(8), c3: synced(7), c4: synced(4) },
    s3: { c1: synced(18), c2: synced(13), c3: synced(14), c4: synced(9) },
    s4: { c1: synced(13), c2: local(10), c3: synced(11), c4: synced(6) },
    s5: { c1: synced(15), c2: synced(11), c3: { mark: ABSENT, sync: 'synced', author: me }, c4: synced(7) },
    s6: { c1: synced(11), c2: synced(8), c3: synced(9), c4: local(5) },
    s7: { c1: synced(19), c2: synced(14), c3: synced(15), c4: synced(10) },
    s8: { c1: { mark: EMPTY, sync: 'synced' }, c2: { mark: EMPTY, sync: 'synced' }, c3: { mark: EMPTY, sync: 'synced' }, c4: { mark: EMPTY, sync: 'synced' } },
  },
}

/* A grid with every cell empty, for assessments with no marks yet. */
export function emptyGrid(studentIds: string[], criterionIds: string[]): Grid {
  const grid: Grid = {}
  for (const s of studentIds) {
    grid[s] = {}
    for (const c of criterionIds) grid[s][c] = { mark: EMPTY, sync: 'synced' }
  }
  return grid
}

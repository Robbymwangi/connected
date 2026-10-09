import type { Assessment, LifecycleStatus, SyncState } from '../fixtures/assessments'
import type { ActiveConflict, Choice, ConflictSide, HistoricalConflict, Proposal, Referral, Resolution } from '../fixtures/conflicts'
import type { Grid, GridCell } from '../fixtures/marks'
import type { SchoolDirectory } from '../lib/schoolDirectory'
import { overlayConflictRow } from './conflictCommands'
import type { LocalRecord } from './localDatabase'
import type { OutboxEntry } from './outbox'
import type { ResultRecord } from '../fixtures/results'
import { sideOf } from './conflicts'
import { ABSENT, EMPTY, score, type Mark, type PerformanceLevel } from './grading'

export type SyncedAssessmentState = {
  assessments: Assessment[]
  marks: Record<string, Grid>
  conflicts: ActiveConflict[]
  history: HistoricalConflict[]
  criteriaBySubject: SchoolDirectory['criteriaBySubject']
  resultRecords: ResultRecord[]
}

type Source = {
  assessments: LocalRecord[]
  marks: LocalRecord[]
  conflicts: LocalRecord[]
  results: LocalRecord[]
  directory: SchoolDirectory
  userId: string
  /* The signed-in teacher's display name, for the commands of theirs that are shown. */
  userName?: string
  /* The conflict commands this device has queued, sent, or had answered but not yet
     pulled; they are shown on top of the conflict rows. */
  commands?: OutboxEntry[]
}

function text(record: Record<string, unknown>, key: string): string {
  return typeof record[key] === 'string' ? record[key] as string : ''
}

function integer(record: Record<string, unknown>, key: string): number | null {
  return Number.isSafeInteger(record[key]) ? record[key] as number : null
}

function records(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? value.filter((item): item is Record<string, unknown> => typeof item === 'object' && item !== null) : []
}

function markFromFields(record: Record<string, unknown>): Mark | null {
  if (record.markKind === 'empty') return EMPTY
  if (record.markKind === 'absent') return ABSENT
  if (record.markKind === 'score' && Number.isSafeInteger(record.score) && (record.score as number) >= 0) {
    return score(record.score as number)
  }
  return null
}

function conflictMark(side: Record<string, unknown>): Mark | null {
  if (side.markKind === 'absent') return ABSENT
  if (side.markKind === 'empty') return EMPTY
  if (side.markKind === 'score' && Number.isSafeInteger(side.score) && (side.score as number) >= 0) {
    return score(side.score as number)
  }
  return null
}

function sideFromWire(value: unknown, names: Map<string, string>): ConflictSide | null {
  if (typeof value !== 'object' || value === null) return null
  const side = value as Record<string, unknown>
  const mark = conflictMark(side)
  const userId = text(side, 'userId')
  const editId = text(side, 'editId')
  if (!mark || !userId || !editId) return null
  const wireName = text(side, 'who')
  return {
    editId,
    userId,
    who: wireName || names.get(userId) || 'Unknown teacher',
    mark,
    at: text(side, 'at'),
  }
}

function choiceFromWire(value: unknown): Choice | null {
  if (typeof value !== 'object' || value === null) return null
  const choice = value as Record<string, unknown>
  if (choice.kind === 'side' && typeof choice.editId === 'string') return { kind: 'side', editId: choice.editId }
  if (choice.kind === 'corrected' && typeof choice.mark === 'object' && choice.mark !== null) {
    const mark = choice.mark as Record<string, unknown>
    if (mark.kind === 'empty') return { kind: 'corrected', mark: EMPTY }
    if (mark.kind === 'absent') return { kind: 'corrected', mark: ABSENT }
    if (mark.kind === 'score' && Number.isSafeInteger(mark.value) && (mark.value as number) >= 0) {
      return { kind: 'corrected', mark: score(mark.value as number) }
    }
  }
  return null
}

function proposalsFromWire(value: unknown): Proposal[] {
  return records(value).flatMap((item) => {
    const choice = choiceFromWire(item.choice)
    if (!choice || typeof item.byId !== 'string' || typeof item.by !== 'string' || typeof item.note !== 'string') return []
    return [{ byId: item.byId, by: item.by, choice, note: item.note, at: text(item, 'at') }]
  })
}

function referralFromWire(value: unknown): Referral | undefined {
  if (typeof value !== 'object' || value === null) return undefined
  const referral = value as Record<string, unknown>
  if (referral.reason === 'rounds') return { reason: 'rounds', at: text(referral, 'at') }
  if (referral.reason === 'party' && typeof referral.byId === 'string' && typeof referral.by === 'string') {
    return { reason: 'party', byId: referral.byId, by: referral.by, at: text(referral, 'at') }
  }
  return undefined
}

function resolutionFromWire(value: unknown): Resolution | null {
  if (typeof value !== 'object' || value === null) return null
  const resolution = value as Record<string, unknown>
  if (resolution.kind === 'auto') return { kind: 'auto' }

  const choice = choiceFromWire(resolution.choice)
  if (resolution.kind === 'self' && choice && typeof resolution.byId === 'string' && typeof resolution.by === 'string') {
    return { kind: 'self', byId: resolution.byId, by: resolution.by, choice }
  }
  if (resolution.kind === 'moderated' && choice && typeof resolution.byId === 'string' && typeof resolution.by === 'string' && typeof resolution.note === 'string') {
    return { kind: 'moderated', byId: resolution.byId, by: resolution.by, choice, note: resolution.note }
  }
  if (
    resolution.kind === 'agreed' && choice &&
    typeof resolution.proposedById === 'string' && typeof resolution.proposedBy === 'string' &&
    typeof resolution.acceptedById === 'string' && typeof resolution.acceptedBy === 'string' &&
    typeof resolution.note === 'string'
  ) {
    return {
      kind: 'agreed',
      proposedById: resolution.proposedById,
      proposedBy: resolution.proposedBy,
      acceptedById: resolution.acceptedById,
      acceptedBy: resolution.acceptedBy,
      choice,
      note: resolution.note,
    }
  }
  return null
}

export function mapSyncedAssessmentState(source: Source): SyncedAssessmentState {
  const active = <T extends LocalRecord>(items: T[]) => items.filter((item) => item.deletedAt == null)
  const studentsByYear = new Map<number, ReturnType<SchoolDirectory['studentsForYear']>>()
  const criteriaBySubject = source.directory.criteriaBySubject
  const names = new Map(source.directory.teachers.map((teacher) => [teacher.id, teacher.name]))
  const assessmentRows = active(source.assessments)
  const allMarkRows = source.marks
  const markRows = active(source.marks)
  const conflictRows = active(source.conflicts)
  const actor = { id: source.userId, name: source.userName ?? names.get(source.userId) ?? 'You' }
  const commandsByConflict = new Map<string, OutboxEntry[]>()
  for (const command of source.commands ?? []) {
    if (command.kind !== 'command' || command.table !== 'conflicts') continue
    commandsByConflict.set(command.recordId, [...(commandsByConflict.get(command.recordId) ?? []), command])
  }
  const overlaid = new Map(conflictRows.map((row) => [row.id, overlayConflictRow(row, commandsByConflict.get(row.id) ?? [], actor)]))
  const resultRecords = active(source.results).flatMap((row): ResultRecord[] => {
    const studentId = text(row, 'studentId')
    const assessmentId = text(row, 'assessmentId')
    const total = integer(row, 'total')
    const max = integer(row, 'max')
    const level = text(row, 'level')
    if (!studentId || !assessmentId || total === null || max === null || max <= 0 || !['EE', 'ME', 'AE', 'BE'].includes(level)) return []
    return [{ studentId, assessmentId, total, max, level: level as PerformanceLevel }]
  })
  const gridByAssessment: Record<string, Grid> = {}
  const markRowsByAssessment = new Map<string, LocalRecord[]>()
  const markById = new Map(allMarkRows.map((mark) => [mark.id, mark]))

  for (const row of markRows) {
    const assessmentId = text(row, 'assessmentId')
    const rows = markRowsByAssessment.get(assessmentId) ?? []
    rows.push(row)
    markRowsByAssessment.set(assessmentId, rows)
  }

  const assessments: Assessment[] = []
  const subjectIdByAssessment = new Map<string, string>()
  for (const row of assessmentRows) {
    const id = row.id
    const version = integer(row, 'version')
    const year = integer(row, 'year')
    const classId = text(row, 'classId')
    const subjectId = text(row, 'subjectId')
    const classRecord = source.directory.classesForYear(year ?? -1).find((item) => item.id === classId)
    const subject = source.directory.subjectNameById[subjectId]
    const criteria = subject ? criteriaBySubject[subject] ?? [] : []
    if (typeof id !== 'string' || version === null || year === null || !classRecord || !subject) continue

    if (!studentsByYear.has(year)) studentsByYear.set(year, source.directory.studentsForYear(year))
    const students = studentsByYear.get(year)!.filter((student) => student.classId === classId)
    const total = students.length * criteria.length
    const studentIds = new Set(students.map((student) => student.id))
    const criterionIds = new Set(criteria.map((criterion) => criterion.id))
    const grid: Grid = Object.fromEntries(students.map((student) => [
      student.id,
      Object.fromEntries(criteria.map((criterion) => [criterion.id, { mark: EMPTY, sync: 'synced' as const }])),
    ]))

    for (const markRow of markRowsByAssessment.get(id) ?? []) {
      const studentId = text(markRow, 'studentId')
      const criterionId = text(markRow, 'criterionId')
      const mark = markFromFields(markRow)
      if (!mark || !studentIds.has(studentId) || !criterionIds.has(criterionId)) continue
      const markVersion = integer(markRow, 'version') ?? 1
      const authorId = text(markRow, 'lastEditedBy')
      const cell: GridCell = {
        mark,
        sync: markVersion === 0 || markRow.sync === 'pending' ? 'local' : 'synced',
        baseVersion: markVersion,
        ...(text(markRow, 'localAuthor') ? { author: text(markRow, 'localAuthor') } : names.has(authorId) ? { author: names.get(authorId) } : {}),
      }
      grid[studentId][criterionId] = cell
    }

    const entered = Object.values(grid).flatMap((student) => Object.values(student)).filter((cell) => cell.mark.kind !== 'empty').length
    const rawStatus = text(row, 'status')
    const status: LifecycleStatus = rawStatus === 'reports-generated' || rawStatus === 'finalized'
      ? rawStatus
      : entered === 0 ? 'scheduled'
      : entered < total ? 'in-progress'
      : 'complete'

    const hasConflict = conflictRows.some((conflict) => {
      const shown = overlaid.get(conflict.id)?.row ?? conflict
      return shown.resolution == null && shown.resolvedAt == null && text(conflict, 'markId') &&
        (markRowsByAssessment.get(id) ?? []).some((mark) => mark.id === conflict.markId)
    })
    const sync: SyncState = hasConflict ? 'conflict' : version === 0 || row.sync === 'pending' ? 'pending' : 'synced'

    subjectIdByAssessment.set(id, subjectId)
    assessments.push({
      id,
      subject,
      stream: classRecord.stream,
      name: text(row, 'name'),
      term: text(row, 'term'),
      year,
      date: text(row, 'date'),
      version,
      entered,
      total,
      status,
      sync,
    })
    gridByAssessment[id] = grid
  }

  const assessmentById = new Map(assessments.map((assessment) => [assessment.id, assessment]))
  const studentsById = new Map<number, Map<string, string>>()
  const studentName = (assessment: Assessment, id: string) => {
    let namesById = studentsById.get(assessment.year)
    if (!namesById) {
      namesById = new Map(source.directory.studentsForYear(assessment.year).map((student) => [student.id, student.name]))
      studentsById.set(assessment.year, namesById)
    }
    return namesById.get(id) ?? id
  }

  const activeConflicts: ActiveConflict[] = []
  const history: HistoricalConflict[] = []
  for (const stored of conflictRows) {
    const applied = overlaid.get(stored.id)
    const row = applied?.row ?? stored
    const markId = text(row, 'markId')
    const linkedMark = markById.get(markId)
    const assessmentId = text(linkedMark ?? {}, 'assessmentId')
    const assessment = assessmentById.get(assessmentId)
    const sideA = sideFromWire(row.sideA, names)
    const sideB = sideFromWire(row.sideB, names)
    const baseVersion = integer(row, 'baseVersion')
    const studentId = text(linkedMark ?? {}, 'studentId')
    const criterionId = text(linkedMark ?? {}, 'criterionId')
    if (!assessment || !sideA || !sideB || baseVersion === null || !studentId || !criterionId) continue
    const mine = sideA.userId === source.userId ? sideA : sideB.userId === source.userId ? sideB : sideA
    const theirs = mine === sideA ? sideB : sideA
    const criterion = (criteriaBySubject[assessment.subject] ?? []).find((item) => item.id === criterionId)
    const common = {
      id: row.id,
      assessmentId,
      subjectId: subjectIdByAssessment.get(assessmentId) ?? '',
      studentId,
      criterionId,
      student: studentName(assessment, studentId),
      criterion: criterion?.name ?? criterionId,
      assessment: `${assessment.subject} ${assessment.name}, ${assessment.term}`,
      mine,
      theirs,
      proposals: proposalsFromWire(row.proposals),
      ...(referralFromWire(row.referral) ? { referral: referralFromWire(row.referral) } : {}),
      ...(applied?.local ? { local: true as const } : {}),
    }
    const resolution = resolutionFromWire(row.resolution)
    if (row.resolution == null) {
      activeConflicts.push({ ...common, baseVersion, conflictVersion: applied?.version ?? integer(row, 'version') ?? 0 })
    } else if (resolution && typeof row.resolvedAt === 'string') {
      history.push({ ...common, resolution, resolvedAt: row.resolvedAt })
      if (applied?.local && resolution.kind !== 'auto') {
        /* This device has resolved it and the server has not said so yet: the cell
           already holds the chosen mark, as a local value, until the pull brings the
           server's own. */
        const choice = resolution.choice
        const by = resolution.kind === 'agreed' ? resolution.acceptedBy : resolution.by
        const chosen = choice.kind === 'corrected'
          ? { mark: choice.mark, author: by }
          : (() => { const picked = sideOf({ ...common, resolution, resolvedAt: row.resolvedAt as string }, choice.editId); return picked ? { mark: picked.mark, author: picked.who } : null })()
        const grid = gridByAssessment[assessmentId]
        if (chosen && grid?.[studentId]?.[criterionId]) {
          grid[studentId][criterionId] = {
            mark: chosen.mark, sync: 'local', author: chosen.author, baseVersion: integer(linkedMark ?? {}, 'version') ?? 0,
          }
        }
      }
    }
  }

  return { assessments, marks: gridByAssessment, conflicts: activeConflicts, history, criteriaBySubject, resultRecords }
}
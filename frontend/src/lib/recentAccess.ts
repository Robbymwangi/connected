import type { Assessment } from '../fixtures/assessments'
import type { SchoolDirectory } from './schoolDirectory'
import type { LocalDatabase } from './localDatabase'
import type { Location } from '../app/location'

export const RECENT_ACCESS_KEY = 'recentAccesses'
export const RECENT_ACCESS_LIMIT = 5

export type RecentDestination =
  | { screen: 'assessments'; assessmentId: string; view: 'grid' | 'report' }
  | { screen: 'classes'; classId: string }
  | { screen: 'classes'; classId: string; studentId: string }
  | { screen: 'classes'; teacherId: string }

export type RecentAccess = {
  key: string
  primary: string
  secondary: string
  destination: RecentDestination
}

function destinationFor(location: Location): RecentDestination | null {
  if (location.screen === 'assessments' && location.assessmentId && location.view) {
    return { screen: 'assessments', assessmentId: location.assessmentId, view: location.view }
  }
  if (location.screen === 'classes' && location.studentId && location.classId) {
    return { screen: 'classes', classId: location.classId, studentId: location.studentId }
  }
  if (location.screen === 'classes' && location.teacherId) {
    return { screen: 'classes', teacherId: location.teacherId }
  }
  if (location.screen === 'classes' && location.classId) {
    return { screen: 'classes', classId: location.classId }
  }
  return null
}

export function recentAccessForLocation(
  location: Location,
  directory: SchoolDirectory | null,
  assessments: Assessment[],
): RecentAccess | null {
  if (!directory) return null
  const destination = destinationFor(location)
  if (!destination) return null

  if (destination.screen === 'assessments') {
    const assessment = assessments.find((item) => item.id === destination.assessmentId)
    if (!assessment) return null
    return {
      key: `assessment:${assessment.id}:${destination.view}`,
      primary: `${assessment.subject}: ${assessment.name}`,
      secondary: `Stream ${assessment.stream} · ${assessment.term}`,
      destination,
    }
  }

  if ('teacherId' in destination) {
    const teacher = directory.teachers.find((item) => item.id === destination.teacherId)
    if (!teacher) return null
    return {
      key: `teacher:${teacher.id}`,
      primary: teacher.name,
      secondary: 'Teacher profile',
      destination,
    }
  }

  const schoolClass = directory.classesForYear(directory.years[0] ?? 0).find((item) => item.id === destination.classId)
  if (!schoolClass) return null
  const classLabel = `${schoolClass.grade} · Stream ${schoolClass.stream}`

  if ('studentId' in destination) {
    const student = directory.years
      .flatMap((year) => directory.studentsForYear(year))
      .find((item) => item.id === destination.studentId && item.classId === destination.classId)
    if (!student) return null
    return {
      key: `student:${destination.classId}:${student.id}`,
      primary: student.name,
      secondary: `Student profile · ${classLabel}`,
      destination,
    }
  }

  return {
    key: `class:${schoolClass.id}`,
    primary: classLabel,
    secondary: `${schoolClass.enrolment} students · ${schoolClass.subjects.length} subjects`,
    destination,
  }
}

function isRecentAccess(value: unknown): value is RecentAccess {
  if (typeof value !== 'object' || value === null) return false
  const item = value as Record<string, unknown>
  if (typeof item.key !== 'string' || typeof item.primary !== 'string' || typeof item.secondary !== 'string') return false
  const destination = item.destination
  if (typeof destination !== 'object' || destination === null) return false
  const target = destination as Record<string, unknown>
  if (target.screen === 'assessments') {
    return typeof target.assessmentId === 'string' && (target.view === 'grid' || target.view === 'report')
  }
  if (target.screen === 'classes') {
    return typeof target.studentId === 'string'
      ? typeof target.classId === 'string'
      : typeof target.teacherId === 'string' || typeof target.classId === 'string'
  }
  return false
}

export async function readRecentAccesses(database: LocalDatabase): Promise<RecentAccess[]> {
  const value = (await database.metadata.get(RECENT_ACCESS_KEY))?.value
  return Array.isArray(value) ? value.filter(isRecentAccess).slice(0, RECENT_ACCESS_LIMIT) : []
}

export async function rememberRecentAccess(database: LocalDatabase, item: RecentAccess): Promise<void> {
  await database.transaction('rw', database.metadata, async () => {
    const stored = (await database.metadata.get(RECENT_ACCESS_KEY))?.value
    const previous = Array.isArray(stored) ? stored.filter(isRecentAccess) : []
    if (previous[0]?.key === item.key) return
    const recent = [item, ...previous.filter((entry) => entry.key !== item.key)].slice(0, RECENT_ACCESS_LIMIT)
    await database.metadata.put({ key: RECENT_ACCESS_KEY, value: recent })
  })
}
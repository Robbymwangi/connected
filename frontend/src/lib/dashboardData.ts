import type { Assessment } from '../fixtures/assessments'
import type { SchoolClass } from '../fixtures/classes'

export type MarkingProgress = {
  inProgressAssessments: number
  marksRemaining: number
}

export function markingProgress(assessments: Assessment[], year: number): MarkingProgress {
  const inProgress = assessments.filter((assessment) => assessment.year === year && assessment.status === 'in-progress')
  const marksRemaining = inProgress.reduce((remaining, assessment) => remaining + Math.max(assessment.total - assessment.entered, 0), 0)

  return { inProgressAssessments: inProgress.length, marksRemaining }
}

export type ClassWorkload = {
  assessmentCount: number
  scheduled: number
  inProgress: number
  needsFinalizing: number
  closed: number
}

export function classWorkload(schoolClass: SchoolClass, assessments: Assessment[], year: number): ClassWorkload {
  const sittings = assessments.filter((assessment) => assessment.stream === schoolClass.stream && assessment.year === year)

  return {
    assessmentCount: sittings.length,
    scheduled: sittings.filter((assessment) => assessment.status === 'scheduled').length,
    inProgress: sittings.filter((assessment) => assessment.status === 'in-progress').length,
    needsFinalizing: sittings.filter((assessment) => assessment.status === 'complete').length,
    closed: sittings.filter((assessment) => assessment.status === 'finalized').length,
  }
}

const QUEUE_ORDER: Record<Assessment['status'], number> = {
  complete: 0,
  'in-progress': 1,
  scheduled: 2,
  finalized: 3,
}

export function pendingAssessmentsForDashboard(assessments: Assessment[]): Assessment[] {
  return assessments
    .filter((assessment) => assessment.status === 'scheduled' || assessment.status === 'in-progress' || assessment.status === 'complete')
    .sort((left, right) => QUEUE_ORDER[left.status] - QUEUE_ORDER[right.status] || left.date.localeCompare(right.date))
}
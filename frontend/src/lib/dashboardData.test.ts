import { describe, expect, it } from 'vitest'
import type { Assessment } from '../fixtures/assessments'
import type { SchoolClass } from '../fixtures/classes'
import { classWorkload, markingProgress, pendingAssessmentsForDashboard } from './dashboardData'

const assessment = (id: string, status: Assessment['status'], over: Partial<Assessment> = {}): Assessment => ({
  id, subject: 'English', stream: '5A', name: id, term: 'Term 1', year: 2026, date: '2026-03-01',
  version: 1, entered: 0, total: 2, status, sync: 'synced', ...over,
})

describe('dashboardData', () => {
  it('counts remaining marks in current-year in-progress assessments only', () => {
    expect(markingProgress([
      assessment('active-one', 'in-progress', { entered: 23, total: 28 }),
      assessment('active-two', 'in-progress', { entered: 18, total: 30 }),
      assessment('scheduled', 'scheduled', { entered: 0, total: 28 }),
      assessment('complete', 'complete', { entered: 28, total: 28 }),
      assessment('other-year', 'in-progress', { year: 2025, entered: 5, total: 28 }),
    ], 2026)).toEqual({ inProgressAssessments: 2, marksRemaining: 17 })
  })

  it('clamps inconsistent assessment counts at zero remaining marks', () => {
    expect(markingProgress([
      assessment('over-entered', 'in-progress', { entered: 3, total: 2 }),
    ], 2026)).toEqual({ inProgressAssessments: 1, marksRemaining: 0 })
  })

  it('counts assessment work by class stream and selected year', () => {
    const schoolClass: SchoolClass = { id: 'class-5a', grade: 'Grade 5', stream: '5A', teacher: 'Teacher', enrolment: 20, subjects: ['English'], assessments: 4 }

    expect(classWorkload(schoolClass, [
      assessment('draft', 'scheduled'),
      assessment('active', 'in-progress'),
      assessment('done', 'complete'),
      assessment('closed', 'finalized'),
      assessment('other-year', 'scheduled', { year: 2025 }),
      assessment('other-stream', 'scheduled', { stream: '5B' }),
    ], 2026)).toEqual({ assessmentCount: 4, scheduled: 1, inProgress: 1, needsFinalizing: 1, closed: 1 })
  })

  it('shows open assessment work first and excludes closed assessments', () => {
    expect(pendingAssessmentsForDashboard([
      assessment('scheduled', 'scheduled'),
      assessment('closed', 'finalized'),
      assessment('active', 'in-progress'),
      assessment('ready', 'complete'),
    ]).map((item) => item.id)).toEqual(['ready', 'active', 'scheduled'])
  })
})
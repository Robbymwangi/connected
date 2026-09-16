import { describe, expect, it } from 'vitest'
import type { Assessment, LifecycleStatus } from '../fixtures/assessments'
import { ALL_SUBJECTS, ALL_TERMS, filterAssessments, sortQueue } from './assessmentQueue'

const make = (id: string, over: Partial<Assessment> = {}): Assessment => ({
  id,
  subject: 'English',
  stream: '4W',
  name: 'CAT 1',
  term: 'Term 1',
  year: 2025,
  date: '2025-03-10',
  entered: 0,
  total: 28,
  status: 'scheduled',
  sync: 'synced',
  ...over,
})

const everything = { year: 2025, status: 'All', term: ALL_TERMS, subject: ALL_SUBJECTS } as const

describe('filterAssessments', () => {
  const list = [
    make('a', { year: 2025, status: 'in-progress', term: 'Term 1', subject: 'English' }),
    make('b', { year: 2025, status: 'complete', term: 'Term 2', subject: 'Maths' }),
    make('c', { year: 2024, status: 'reports-generated', term: 'Term 2', subject: 'English' }),
  ]

  it('always narrows by year', () => {
    expect(filterAssessments(list, everything).map((a) => a.id)).toEqual(['a', 'b'])
    expect(filterAssessments(list, { ...everything, year: 2024 }).map((a) => a.id)).toEqual(['c'])
  })
  it('maps a status group to its lifecycle statuses', () => {
    expect(filterAssessments(list, { ...everything, status: 'Open' }).map((a) => a.id)).toEqual(['a'])
    expect(filterAssessments(list, { ...everything, status: 'Needs action' }).map((a) => a.id)).toEqual(['b'])
    expect(filterAssessments(list, { ...everything, status: 'Closed' })).toEqual([])
  })
  it('narrows by term and subject when one is chosen', () => {
    expect(filterAssessments(list, { ...everything, term: 'Term 2' }).map((a) => a.id)).toEqual(['b'])
    expect(filterAssessments(list, { ...everything, subject: 'Maths' }).map((a) => a.id)).toEqual(['b'])
  })
})

describe('sortQueue', () => {
  it('puts what needs the teacher first and keeps order within a group', () => {
    const statuses: LifecycleStatus[] = ['reports-generated', 'scheduled', 'in-progress', 'complete', 'in-progress', 'finalized']
    const list = statuses.map((status, i) => make(`${i}`, { status }))
    expect(sortQueue(list).map((a) => a.status)).toEqual([
      'complete', 'in-progress', 'in-progress', 'scheduled', 'finalized', 'reports-generated',
    ])
    expect(sortQueue(list).filter((a) => a.status === 'in-progress').map((a) => a.id)).toEqual(['2', '4'])
  })
  it('does not mutate its input', () => {
    const list = [make('x', { status: 'finalized' }), make('y', { status: 'complete' })]
    sortQueue(list)
    expect(list.map((a) => a.id)).toEqual(['x', 'y'])
  })
})

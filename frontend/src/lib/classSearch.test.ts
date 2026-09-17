import { describe, expect, it } from 'vitest'
import { classes } from '../fixtures/classes'
import { students } from '../fixtures/students'
import { teachers } from '../fixtures/teachers'
import { searchClasses } from './classSearch'

describe('searchClasses', () => {
  it('returns everything but no students for an empty query', () => {
    const r = searchClasses('', classes, teachers, students)
    expect(r.classes).toHaveLength(classes.length)
    expect(r.teachers).toHaveLength(teachers.length)
    expect(r.students).toEqual([])
  })
  it('matches classes by grade, stream, or teacher, case-insensitively', () => {
    expect(searchClasses('grade 5', classes, teachers, students).classes.map((c) => c.stream)).toEqual(['5A', '5B'])
    expect(searchClasses('akinyi', classes, teachers, students).classes.map((c) => c.stream)).toEqual(['4E', '6A'])
  })
  it('needs two characters before it lists students', () => {
    expect(searchClasses('a', classes, teachers, students).students).toEqual([])
    expect(searchClasses('am', classes, teachers, students).students.length).toBeGreaterThan(0)
  })
})

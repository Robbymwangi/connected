import type { SchoolClass } from '../fixtures/classes'
import type { Student } from '../fixtures/students'
import type { Teacher } from '../fixtures/teachers'

/* One search box over three record types. Students only match from two
   characters, so a single letter does not list the whole school. */
export const STUDENT_MIN_QUERY = 2

export function searchClasses(query: string, classes: SchoolClass[], teachers: Teacher[], students: Student[]) {
  const q = query.trim().toLowerCase()
  const has = (text: string) => text.toLowerCase().includes(q)
  return {
    classes: q === '' ? classes : classes.filter((c) => has(`${c.grade} ${c.stream} ${c.teacher}`)),
    teachers: q === '' ? teachers : teachers.filter((t) => has(t.name)),
    students: q.length >= STUDENT_MIN_QUERY ? students.filter((s) => has(s.name)) : [],
  }
}

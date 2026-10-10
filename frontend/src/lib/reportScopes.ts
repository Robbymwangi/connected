import { classes as fixtureClasses, type SchoolClass } from '../fixtures/classes'
import type { Subject } from '../fixtures/rubrics'
import type { Teacher } from '../fixtures/teachers'
import type { Scope } from './analytics'

/* The scopes a report can be about: a stream and a subject, or a stream overall. */

export type ScopeOption = Scope & { label: string; grade: string }

export function scopeLabel(scope: Scope): string {
  return `${scope.stream} · ${scope.subject}`
}

export function gradeOf(stream: string, schoolClasses: SchoolClass[] = fixtureClasses): string | undefined {
  return schoolClasses.find((c) => c.stream === stream)?.grade
}

/* What this teacher teaches, plus Overall for each of their streams. */
export function myScopes(teacher: Teacher, schoolClasses: SchoolClass[] = fixtureClasses): ScopeOption[] {
  const out: ScopeOption[] = []
  for (const [stream, taught] of Object.entries(teacher.subjectsByStream)) {
    const grade = gradeOf(stream, schoolClasses) ?? ''
    for (const subject of taught) out.push({ stream, subject: subject as Subject, grade, label: scopeLabel({ stream, subject: subject as Subject }) })
    out.push({ stream, subject: 'Overall', grade, label: scopeLabel({ stream, subject: 'Overall' }) })
  }
  return out
}

/* Every stream in the school with the subjects it offers, plus Overall. */
export function allScopes(schoolClasses: SchoolClass[] = fixtureClasses): Array<{ grade: string; streams: Array<{ cls: SchoolClass; scopes: ScopeOption[] }> }> {
  const grades = [...new Set(schoolClasses.map((c) => c.grade))].sort()
  return grades.map((grade) => ({
    grade,
    streams: schoolClasses
      .filter((c) => c.grade === grade)
      .map((cls) => ({
        cls,
        scopes: [
          ...cls.subjects.map((subject) => ({ stream: cls.stream, subject: subject as Subject, grade, label: scopeLabel({ stream: cls.stream, subject: subject as Subject }) })),
          { stream: cls.stream, subject: 'Overall' as const, grade, label: scopeLabel({ stream: cls.stream, subject: 'Overall' }) },
        ],
      })),
  }))
}

export function sameScope(a: Scope | null, b: Scope | null): boolean {
  return !!a && !!b && a.stream === b.stream && a.subject === b.subject
}

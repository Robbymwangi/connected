import { classes, type SchoolClass } from '../fixtures/classes'
import { subjects, type Subject } from '../fixtures/rubrics'
import type { Teacher } from '../fixtures/teachers'
import type { Scope } from './analytics'

/* The scopes a report can be about: a stream and a subject, or a stream overall. */

export type ScopeOption = Scope & { label: string; grade: string }

export function scopeLabel(scope: Scope): string {
  return `${scope.stream} · ${scope.subject}`
}

export function gradeOf(stream: string): string | undefined {
  return classes.find((c) => c.stream === stream)?.grade
}

/* What this teacher teaches, plus Overall for each of their streams. */
export function myScopes(teacher: Teacher): ScopeOption[] {
  const out: ScopeOption[] = []
  for (const [stream, taught] of Object.entries(teacher.subjectsByStream)) {
    const grade = gradeOf(stream) ?? ''
    for (const subject of taught) out.push({ stream, subject: subject as Subject, grade, label: scopeLabel({ stream, subject: subject as Subject }) })
    out.push({ stream, subject: 'Overall', grade, label: scopeLabel({ stream, subject: 'Overall' }) })
  }
  return out
}

/* Every stream in the school with the subjects it offers, plus Overall. */
export function allScopes(): Array<{ grade: string; streams: Array<{ cls: SchoolClass; scopes: ScopeOption[] }> }> {
  const grades = [...new Set(classes.map((c) => c.grade))].sort()
  return grades.map((grade) => ({
    grade,
    streams: classes
      .filter((c) => c.grade === grade)
      .map((cls) => ({
        cls,
        scopes: [
          ...subjects.filter((s) => cls.subjects.includes(s)).map((subject) => ({ stream: cls.stream, subject, grade, label: scopeLabel({ stream: cls.stream, subject }) })),
          { stream: cls.stream, subject: 'Overall' as const, grade, label: scopeLabel({ stream: cls.stream, subject: 'Overall' }) },
        ],
      })),
  }))
}

export function sameScope(a: Scope | null, b: Scope | null): boolean {
  return !!a && !!b && a.stream === b.stream && a.subject === b.subject
}

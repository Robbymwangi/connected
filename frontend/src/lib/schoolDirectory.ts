import type { SchoolClass } from '../fixtures/classes'
import type { Student } from '../fixtures/students'
import type { Teacher } from '../fixtures/teachers'
import type { Criterion } from '../fixtures/rubrics'
import type { LocalRecord } from './localDatabase'

export type SchoolDirectorySource = {
  classes: LocalRecord[]
  classSubjects: LocalRecord[]
  criteria: LocalRecord[]
  enrolments: LocalRecord[]
  students: LocalRecord[]
  subjects: LocalRecord[]
  teacherAssignments: LocalRecord[]
  users: LocalRecord[]
  assessments: LocalRecord[]
}

export type SchoolDirectory = {
  years: number[]
  subjects: string[]
  criteriaBySubject: Record<string, Criterion[]>
  teachers: Teacher[]
  classesForYear: (year: number) => SchoolClass[]
  studentsForYear: (year: number) => Student[]
}

const active = (records: LocalRecord[]) => records.filter((record) => record.deletedAt == null)

function stringField(record: LocalRecord, key: string): string {
  const value = record[key]
  return typeof value === 'string' ? value : ''
}

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).map((part) => part[0] ?? '').join('').slice(0, 2).toUpperCase()
}

export function mapSchoolDirectory(source: SchoolDirectorySource): SchoolDirectory {
  const classes = active(source.classes)
  const classSubjects = active(source.classSubjects)
  const criteria = active(source.criteria)
  const enrolments = active(source.enrolments)
  const students = active(source.students)
  const subjects = active(source.subjects)
  const teacherAssignments = active(source.teacherAssignments)
  const users = active(source.users)
  const assessments = active(source.assessments)
  const classById = new Map(classes.map((record) => [record.id, record]))
  const subjectNameById = new Map(subjects.map((record) => [record.id, stringField(record, 'name')]))
  const userById = new Map(users.map((record) => [record.id, record]))
  const names = [...new Set(subjects.map((record) => stringField(record, 'name')).filter(Boolean))].sort()
  const criteriaBySubject: Record<string, Criterion[]> = {}

  for (const criterion of criteria) {
    const subject = subjectNameById.get(stringField(criterion, 'subjectId'))
    const id = stringField(criterion, 'id')
    const name = stringField(criterion, 'name')
    const max = criterion.maxScore
    if (!subject || !id || !name || typeof max !== 'number') continue
    ;(criteriaBySubject[subject] ??= []).push({ id, name, max })
  }

  for (const rubric of Object.values(criteriaBySubject)) rubric.sort((a, b) => a.id.localeCompare(b.id))

  const subjectsByClass = new Map<string, Set<string>>()
  for (const link of classSubjects) {
    const classId = stringField(link, 'classId')
    const subject = subjectNameById.get(stringField(link, 'subjectId'))
    if (!classById.has(classId) || !subject) continue
    const entries = subjectsByClass.get(classId) ?? new Set<string>()
    entries.add(subject)
    subjectsByClass.set(classId, entries)
  }

  const assignedTeacherIds = new Set<string>()
  const subjectsByTeacherStream = new Map<string, Map<string, Set<string>>>()
  for (const assignment of teacherAssignments) {
    const userId = stringField(assignment, 'userId')
    const cls = classById.get(stringField(assignment, 'classId'))
    const subject = subjectNameById.get(stringField(assignment, 'subjectId'))
    if (!userById.has(userId) || !cls || !subject) continue
    assignedTeacherIds.add(userId)
    const stream = stringField(cls, 'stream')
    const byStream = subjectsByTeacherStream.get(userId) ?? new Map<string, Set<string>>()
    const taught = byStream.get(stream) ?? new Set<string>()
    taught.add(subject)
    byStream.set(stream, taught)
    subjectsByTeacherStream.set(userId, byStream)
  }

  const homeStreamByTeacher = new Map<string, string>()
  for (const cls of classes) {
    const teacherId = stringField(cls, 'classTeacherId')
    if (!teacherId || !userById.has(teacherId)) continue
    assignedTeacherIds.add(teacherId)
    if (!homeStreamByTeacher.has(teacherId)) homeStreamByTeacher.set(teacherId, stringField(cls, 'stream'))
  }

  const teachers = [...assignedTeacherIds].flatMap((id) => {
    const user = userById.get(id)
    if (!user) return []
    const name = stringField(user, 'name')
    const byStream = subjectsByTeacherStream.get(id)
    return [{
      id,
      name,
      initials: initials(name),
      email: stringField(user, 'email'),
      phone: '',
      homeStream: homeStreamByTeacher.get(id) ?? null,
      subjectsByStream: Object.fromEntries(
        [...(byStream ?? new Map())].map(([stream, taught]) => [stream, [...taught].sort()]),
      ),
    }]
  }).sort((a, b) => a.name.localeCompare(b.name))

  const years = [...new Set(enrolments.map((record) => record.year).filter((year): year is number => Number.isInteger(year)))].sort((a, b) => b - a)

  const classesForYear = (year: number): SchoolClass[] => classes.map((cls) => {
    const classId = stringField(cls, 'id')
    const teacherId = stringField(cls, 'classTeacherId')
    return {
      id: classId,
      grade: stringField(cls, 'grade'),
      stream: stringField(cls, 'stream'),
      teacher: teacherId ? stringField(userById.get(teacherId) ?? ({} as LocalRecord), 'name') : 'Unassigned',
      enrolment: enrolments.filter((record) => record.classId === classId && record.year === year).length,
      subjects: [...(subjectsByClass.get(classId) ?? [])].sort(),
      assessments: assessments.filter((record) => record.classId === classId && record.year === year).length,
    }
  })

  const studentsForYear = (year: number): Student[] => {
    const result: Student[] = []
    for (const enrolment of enrolments) {
      if (enrolment.year !== year) continue
      const studentId = stringField(enrolment, 'studentId')
      const student = students.find((record) => record.id === studentId)
      const classId = stringField(enrolment, 'classId')
      if (!student || !classById.has(classId)) continue
      const gender = student.gender
      if (gender !== 'F' && gender !== 'M') continue
      result.push({
        id: studentId,
        classId,
        name: stringField(student, 'name'),
        gender,
        dob: stringField(student, 'dob'),
      })
    }
    return result.sort((a, b) => a.name.localeCompare(b.name))
  }

  return { years, subjects: names, criteriaBySubject, teachers, classesForYear, studentsForYear }
}
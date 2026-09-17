import { Search } from 'lucide-react'
import { useState } from 'react'
import type { SessionStore } from '../../app/useSessionStore'
import { FilterDropdown } from '../../components/FilterDropdown'
import { classes, grades } from '../../fixtures/classes'
import { initials, students } from '../../fixtures/students'
import { teachers } from '../../fixtures/teachers'
import { searchClasses } from '../../lib/classSearch'
import { ClassCard } from './ClassCard'
import { StreamDetail } from './StreamDetail'
import { StudentProfile } from './StudentProfile'
import { TeacherCard } from './TeacherCard'
import { TeacherDetail } from './TeacherDetail'

const YEARS = ['2025', '2024'] as const
const TABS = ['classes', 'teachers'] as const
type Tab = (typeof TABS)[number]

type ClassesScreenProps = {
  store: SessionStore
  classId?: string
  studentId?: string
  teacherId?: string
  onOpenClass: (classId: string) => void
  onOpenStudent: (classId: string, studentId: string) => void
  onOpenTeacher: (teacherId: string) => void
  onBackToList: () => void
  onOpenGrid: (assessmentId: string) => void
  onOpenReport: (assessmentId: string) => void
}

export function ClassesScreen(props: ClassesScreenProps) {
  const { store, classId, studentId, teacherId, onOpenClass, onOpenStudent, onOpenTeacher, onBackToList, onOpenGrid, onOpenReport } = props
  const [tab, setTab] = useState<Tab>('classes')
  const [year, setYear] = useState<(typeof YEARS)[number]>('2025')
  const [query, setQuery] = useState('')

  const cls = classId ? classes.find((c) => c.id === classId) : undefined
  const student = cls && studentId ? students.find((s) => s.id === studentId && s.classId === cls.id) : undefined
  const teacher = teacherId ? teachers.find((t) => t.id === teacherId) : undefined

  if (cls && student) {
    return <StudentProfile student={student} cls={cls} store={store} onBack={() => onOpenClass(cls.id)} onBackToList={onBackToList} />
  }
  if (cls) {
    return (
      <StreamDetail
        cls={cls}
        year={Number(year)}
        store={store}
        onBack={onBackToList}
        onOpenStudent={(sid) => onOpenStudent(cls.id, sid)}
        onOpenTeacher={onOpenTeacher}
        onOpenGrid={onOpenGrid}
        onOpenReport={onOpenReport}
      />
    )
  }
  if (teacher) {
    return <TeacherDetail teacher={teacher} onBack={onBackToList} onOpenClass={onOpenClass} />
  }

  const found = searchClasses(query, classes, teachers, students)
  const classOf = (classId: string) => classes.find((c) => c.id === classId)

  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">Classes</h1>
          <p className="mt-1 text-sm font-medium text-muted-foreground">
            {classes.length} streams · {teachers.length} teachers
          </p>
        </div>
        <div className="flex items-center gap-2">
          <FilterDropdown label="Year" value={year} options={YEARS} onChange={setYear} />
          <div className="flex items-center gap-1 rounded-xl border border-border bg-muted/60 p-1" role="group" aria-label="Show">
            {TABS.map((t) => (
              <button
                key={t}
                type="button"
                aria-pressed={tab === t}
                onClick={() => setTab(t)}
                className={`rounded-lg px-4 py-1.5 text-xs font-semibold capitalize transition-colors ${
                  tab === t ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
                }`}
              >
                {t} ({t === 'classes' ? classes.length : teachers.length})
              </button>
            ))}
          </div>
        </div>
      </div>

      <div className="relative mb-5 max-w-md">
        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search classes, teachers, students"
          aria-label="Search classes, teachers, and students"
          className="w-full rounded-xl border border-border bg-card py-2 pr-3 pl-9 text-sm text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/30 focus:outline-none"
        />
      </div>

      {found.students.length > 0 && (
        <section className="mb-7">
          <h2 className="mb-3 text-xs font-bold tracking-wider text-muted-foreground uppercase">Students</h2>
          <div className="flex flex-col gap-2">
            {found.students.slice(0, 8).map((s) => {
              const c = classOf(s.classId)
              return (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => onOpenStudent(s.classId, s.id)}
                  className="flex items-center gap-3 rounded-2xl border border-border bg-card px-5 py-3 text-left shadow-sm transition-colors hover:bg-muted/20"
                >
                  <span className="flex size-8 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-[11px] font-bold text-primary">
                    {initials(s.name)}
                  </span>
                  <span className="flex-1 text-sm font-medium text-foreground">{s.name}</span>
                  <span className="text-xs text-muted-foreground">{c ? `${c.grade} · ${c.stream}` : ''}</span>
                </button>
              )
            })}
          </div>
        </section>
      )}

      {tab === 'classes' &&
        (year === '2024' ? (
          <p className="py-16 text-center text-sm text-muted-foreground">No class data available for 2024 yet.</p>
        ) : (
          grades.map((grade) => {
            const inGrade = found.classes.filter((c) => c.grade === grade)
            if (inGrade.length === 0) return null
            return (
              <section key={grade} className="mb-7">
                <h2 className="mb-3 text-xs font-bold tracking-wider text-muted-foreground uppercase">{grade}</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {inGrade.map((c) => (
                    <ClassCard key={c.id} cls={c} onOpen={() => onOpenClass(c.id)} />
                  ))}
                </div>
              </section>
            )
          })
        ))}

      {tab === 'teachers' && (
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
          {found.teachers.map((t) => (
            <TeacherCard key={t.id} teacher={t} onOpen={() => onOpenTeacher(t.id)} />
          ))}
        </div>
      )}
    </div>
  )
}

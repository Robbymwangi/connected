import { ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react'
import { useRef, useState } from 'react'
import { Popover } from '../../components/Popover'
import type { Scope } from '../../lib/analytics'
import { allScopes, myScopes, sameScope, scopeLabel, type ScopeOption } from '../../lib/reportScopes'
import type { Teacher } from '../../fixtures/teachers'

type ScopePickerProps = {
  value: Scope | null
  onChange: (scope: Scope) => void
  teacher: Teacher
  /* When set, only scopes in this grade can be chosen (the compare side). */
  lockedGrade?: string
  placeholder?: string
  label: string
}

type Level = { kind: 'root' } | { kind: 'grade'; grade: string } | { kind: 'stream'; grade: string; stream: string }

/* My classes first; then every class, drilled grade, stream, subject. */
export function ScopePicker({ value, onChange, teacher, lockedGrade, placeholder = 'Choose scope', label }: ScopePickerProps) {
  const [open, setOpen] = useState(false)
  const [level, setLevel] = useState<Level>({ kind: 'root' })
  const triggerRef = useRef<HTMLButtonElement>(null)
  const all = allScopes()

  const allowed = (grade: string) => !lockedGrade || grade === lockedGrade
  const pick = (s: ScopeOption) => {
    if (!allowed(s.grade)) return
    onChange({ stream: s.stream, subject: s.subject })
    setOpen(false)
    setLevel({ kind: 'root' })
  }
  const row = 'flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition-colors hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-40'

  return (
    <div className="relative">
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label={label}
        aria-expanded={open}
        className={`flex items-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-medium transition-colors ${
          value ? 'border-primary/30 bg-primary/10 text-primary hover:bg-primary/15' : 'border-border bg-card text-muted-foreground hover:bg-muted'
        }`}
      >
        <span>{value ? scopeLabel(value) : placeholder}</span>
        <ChevronDown className="size-3.5 shrink-0" />
      </button>
      <Popover open={open} onClose={() => setOpen(false)} anchor="top-left" triggerRef={triggerRef} className="w-72 bg-card backdrop-blur-lg">
        <div className="flex items-center gap-2 border-b border-border/60 px-3 py-2.5">
          {level.kind !== 'root' && (
            <button
              type="button"
              onClick={() => setLevel(level.kind === 'stream' ? { kind: 'grade', grade: level.grade } : { kind: 'root' })}
              aria-label="Back"
              className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
            >
              <ChevronLeft className="size-4" />
            </button>
          )}
          <span className="text-xs font-semibold text-foreground">
            {level.kind === 'root' ? 'Select scope' : level.kind === 'grade' ? level.grade : `${level.grade} · ${level.stream}`}
          </span>
        </div>

        {level.kind === 'root' && (
          <div className="max-h-80 overflow-y-auto py-1.5">
            <p className="px-4 pt-1 pb-1 text-[10px] font-bold tracking-wider text-muted-foreground uppercase">My classes</p>
            {myScopes(teacher).map((s) => (
              <button key={s.label} type="button" disabled={!allowed(s.grade)} onClick={() => pick(s)} className={row}>
                <span className={sameScope(s, value) ? 'font-semibold text-foreground' : 'text-foreground/80'}>{s.label}</span>
                {!allowed(s.grade) && <span className="text-[10px] text-muted-foreground">Other grade</span>}
              </button>
            ))}
            <p className="px-4 pt-3 pb-1 text-[10px] font-bold tracking-wider text-muted-foreground uppercase">All classes</p>
            {all.map((g) => (
              <button key={g.grade} type="button" disabled={!allowed(g.grade)} onClick={() => setLevel({ kind: 'grade', grade: g.grade })} className={row}>
                <span>
                  <span className="font-medium">{g.grade}</span>
                  <span className="ml-2 text-xs text-muted-foreground">{g.streams.length} streams</span>
                </span>
                <ChevronRight className="size-4 text-muted-foreground" />
              </button>
            ))}
          </div>
        )}

        {level.kind === 'grade' && (
          <div className="py-1.5">
            {all.find((g) => g.grade === level.grade)?.streams.map(({ cls }) => (
              <button key={cls.id} type="button" onClick={() => setLevel({ kind: 'stream', grade: level.grade, stream: cls.stream })} className={row}>
                <span>
                  <span className="font-medium">Stream {cls.stream}</span>
                  <span className="ml-2 text-xs text-muted-foreground">{cls.teacher}</span>
                </span>
                <ChevronRight className="size-4 text-muted-foreground" />
              </button>
            ))}
          </div>
        )}

        {level.kind === 'stream' && (
          <div className="py-1.5">
            {all.find((g) => g.grade === level.grade)?.streams.find((s) => s.cls.stream === level.stream)?.scopes.map((s) => (
              <button key={s.label} type="button" onClick={() => pick(s)} className={row}>
                <span className={sameScope(s, value) ? 'font-semibold text-foreground' : 'text-foreground/80'}>{s.subject}</span>
              </button>
            ))}
          </div>
        )}
      </Popover>
    </div>
  )
}

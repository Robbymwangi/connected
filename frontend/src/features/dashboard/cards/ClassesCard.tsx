import { useState } from 'react'
import { Card } from '../../../components/Card'
import type { Assessment } from '../../../fixtures/assessments'
import type { SchoolClass } from '../../../fixtures/classes'
import { ClassStatsPanel } from './ClassStatsPanel'

type ClassesCardProps = {
  classes: SchoolClass[]
  assessments: Assessment[]
  year: number
  onExpand: () => void
}

/* The class selector is the dashboard's one scope control. It changes which class
   the figures describe; it does not drill into anything. */
export function ClassesCard({ classes, assessments, year, onExpand }: ClassesCardProps) {
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const selected = classes.find((schoolClass) => schoolClass.id === selectedId) ?? classes[0] ?? null

  return (
    <Card
      title="My Classes"
      onExpand={onExpand}
      expandLabel="Open reports"
      action={
        <select
          value={selected?.id ?? ''}
          onChange={(e) => setSelectedId(e.target.value)}
          aria-label="Class"
          disabled={classes.length === 0}
          className="cursor-pointer rounded-lg border border-border bg-muted/60 px-2.5 py-1.5 text-xs font-medium text-muted-foreground focus:ring-2 focus:ring-primary/30 focus:outline-none"
        >
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>{schoolClass.grade}, {schoolClass.stream}</option>
          ))}
        </select>
      }
    >
      {selected
        ? <ClassStatsPanel schoolClass={selected} assessments={assessments} year={year} />
        : <p className="px-5 py-8 text-center text-sm text-muted-foreground">No classes are available on this device.</p>}
    </Card>
  )
}

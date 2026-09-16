import { useState } from 'react'
import { Card } from '../../../components/Card'
import { classOptions, classStats } from '../../../fixtures/dashboard'
import { ClassStatsPanel } from './ClassStatsPanel'

type ClassesCardProps = {
  onExpand: () => void
}

/* The class selector is the dashboard's one scope control. It changes which class
   the figures describe; it does not drill into anything. */
export function ClassesCard({ onExpand }: ClassesCardProps) {
  const [selected, setSelected] = useState(classOptions[0])

  return (
    <Card
      title="My Classes"
      onExpand={onExpand}
      expandLabel="Open reports"
      action={
        <select
          value={selected}
          onChange={(e) => setSelected(e.target.value)}
          aria-label="Class"
          className="cursor-pointer rounded-lg border border-border bg-muted/60 px-2.5 py-1.5 text-xs font-medium text-muted-foreground focus:ring-2 focus:ring-primary/30 focus:outline-none"
        >
          {classOptions.map((option) => (
            <option key={option}>{option}</option>
          ))}
        </select>
      }
    >
      <ClassStatsPanel stats={classStats[selected]} />
    </Card>
  )
}

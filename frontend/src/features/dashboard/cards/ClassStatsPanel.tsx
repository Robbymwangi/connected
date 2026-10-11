import type { Assessment } from '../../../fixtures/assessments'
import type { SchoolClass } from '../../../fixtures/classes'
import { classWorkload } from '../../../lib/dashboardData'
import { KpiTile } from '../../../components/KpiTile'

export function ClassStatsPanel({ schoolClass, assessments, year }: { schoolClass: SchoolClass; assessments: Assessment[]; year: number }) {
  const workload = classWorkload(schoolClass, assessments, year)
  return (
    <div className="flex flex-1 flex-col gap-4 px-5 pt-4 pb-5">
      <p className="text-xs text-muted-foreground">
        Grade {schoolClass.grade.replace(/^Grade\s+/i, '')} · Stream {schoolClass.stream} · {year}
      </p>
      <div className="grid grid-cols-2 gap-3">
        <KpiTile label="Students" value={schoolClass.enrolment} />
        <KpiTile label="Assessments" value={workload.assessmentCount} />
        <KpiTile label="In progress" value={workload.inProgress} />
        <KpiTile label="Needs finalizing" value={workload.needsFinalizing} />
      </div>
    </div>
  )
}

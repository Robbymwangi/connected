import { useMemo } from 'react'
import type { SessionStore } from '../../app/useSessionStore'
import { classes } from '../../fixtures/classes'
import { results } from '../../fixtures/results'
import { rosterFor } from '../../fixtures/students'
import {
  assessmentsInScope,
  criterionBreakdown,
  needingAttention,
  outcomesFor,
  summarise,
  trend,
  type Data,
  type ReportFilters,
  type Scope,
} from '../../lib/analytics'

/* Everything a report shows for one scope, computed from the store. */
export function useReport(scope: Scope | null, filters: ReportFilters, store: SessionStore) {
  return useMemo(() => {
    if (!scope) return null
    const cls = classes.find((c) => c.stream === scope.stream)
    const data: Data = { assessments: store.assessments, marks: store.marks, records: results, roster: rosterFor(cls?.id ?? '') }
    const assessments = assessmentsInScope(scope, filters, store.assessments)
    const outcomeSets = assessments.map((a) => outcomesFor(a, data))
    return {
      scope,
      assessments,
      summary: summarise(outcomeSets),
      criteria: criterionBreakdown(assessments, data),
      trend: trend(assessments, data),
      attention: needingAttention(assessments, data),
      roster: data.roster,
    }
  }, [scope, filters, store.assessments, store.marks])
}

export type Report = NonNullable<ReturnType<typeof useReport>>

import { FilterDropdown } from '../../components/FilterDropdown'
import { TERMS as SCHOOL_TERMS, type Assessment } from '../../fixtures/assessments'
import { subjects } from '../../fixtures/rubrics'
import {
  ALL_SUBJECTS,
  ALL_TERMS,
  filterAssessments,
  sortQueue,
  STATUS_GROUPS,
  type QueueFilters,
  type StatusGroup,
} from '../../lib/assessmentQueue'
import { AssessmentRow } from './AssessmentRow'

const YEARS = ['2025', '2024'] as const
/* Derived from the same list the create dialog offers, so every term a record can
   carry is one the queue can filter for. */
const TERMS = [ALL_TERMS, ...SCHOOL_TERMS] as const
const SUBJECTS = [ALL_SUBJECTS, ...subjects] as const
const STATUSES = Object.keys(STATUS_GROUPS) as StatusGroup[]

type QueueListProps = {
  assessments: Assessment[]
  filters: QueueFilters
  onFiltersChange: (update: (f: QueueFilters) => QueueFilters) => void
  onOpenGrid: (id: string) => void
  onOpenReport: (id: string) => void
}

/* Filters live in the screen so the header can report how many rows they left. */
export function QueueList({ assessments, filters, onFiltersChange: setFilters, onOpenGrid, onOpenReport }: QueueListProps) {
  const rows = sortQueue(filterAssessments(assessments, filters))

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <FilterDropdown
          label="Year"
          value={String(filters.year) as (typeof YEARS)[number]}
          options={YEARS}
          onChange={(year) => setFilters((f) => ({ ...f, year: Number(year) }))}
        />
        <FilterDropdown
          label="Status"
          value={filters.status}
          options={STATUSES}
          onChange={(status) => setFilters((f) => ({ ...f, status }))}
        />
        <FilterDropdown
          label="Term"
          value={filters.term as (typeof TERMS)[number]}
          options={TERMS}
          onChange={(term) => setFilters((f) => ({ ...f, term }))}
        />
        <FilterDropdown
          label="Subject"
          value={filters.subject as (typeof SUBJECTS)[number]}
          options={SUBJECTS}
          onChange={(subject) => setFilters((f) => ({ ...f, subject }))}
        />
      </div>

      <div className="flex flex-col gap-2">
        {rows.length === 0 && (
          <p className="py-16 text-center text-sm text-muted-foreground">
            No assessments match the current filters.
          </p>
        )}
        {rows.map((a) => (
          <AssessmentRow
            key={a.id}
            assessment={a}
            onOpenGrid={() => onOpenGrid(a.id)}
            onOpenReport={() => onOpenReport(a.id)}
          />
        ))}
      </div>
    </>
  )
}

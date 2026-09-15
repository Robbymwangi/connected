import { BackNav } from '../../components/BackNav'
import type { Assessment } from '../../fixtures/assessments'

type AssessmentPlaceholderProps = {
  assessment: Assessment
  view: 'grid' | 'report'
  onBack: () => void
}

/* Stands in for the marking grid and the assessment report until they are built,
   so the navigation shape around them is already in place. */
export function AssessmentPlaceholder({ assessment: a, view, onBack }: AssessmentPlaceholderProps) {
  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <BackNav
        onBack={onBack}
        items={[
          { label: 'Assessments', onClick: onBack },
          { label: `${a.subject} · ${a.stream}` },
          { label: view === 'grid' ? a.name : 'Report' },
        ]}
      />
      <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">
        {a.subject}: {a.stream}
      </h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {a.name} · {a.term}
      </p>
      <p className="mt-6 text-sm text-muted-foreground">
        {view === 'grid' ? 'Marking grid arrives in the next session.' : 'Assessment report arrives with Reports.'}
      </p>
    </div>
  )
}

import { BackNav } from '../../components/BackNav'
import type { Assessment } from '../../fixtures/assessments'

type AssessmentPlaceholderProps = {
  assessment: Assessment
  onBack: () => void
}

/* Stands in for the assessment report until Reports is built, so the navigation
   shape around it is already in place. */
export function AssessmentPlaceholder({ assessment: a, onBack }: AssessmentPlaceholderProps) {
  return (
    <div className="px-5 pt-6 pb-12 lg:px-8">
      <BackNav
        onBack={onBack}
        items={[
          { label: 'Assessments', onClick: onBack },
          { label: `${a.subject} · ${a.stream}` },
          { label: 'Report' },
        ]}
      />
      <h1 className="text-2xl leading-tight font-bold tracking-tight text-foreground">
        {a.subject}: {a.stream}
      </h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {a.name} · {a.term}
      </p>
      <p className="mt-6 text-sm text-muted-foreground">
        Assessment report arrives with Reports.
      </p>
    </div>
  )
}

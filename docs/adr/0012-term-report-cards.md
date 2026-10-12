# 12. Reports are per student per term; comments follow the report

Date: 2026-10-12

## Status

Accepted 2026-10-12. Reverses decision 8 of `docs/spec/data-model.md`. The unit of a
term's mean, left open when this was proposed, is settled: the mean of the term's assessment
percentages, each sitting weighted equally (`docs/spec/workflow.md`, Report cards). Decision 5
is narrowed for the report assistant alone by ADR 0013, which is still proposed.

## Context

Decision 8 made a report one per student per assessment, matching `results`, and
rejected a per-class-per-term grain so that "End of Term" would stay an ordinary
assessment. Building towards 4.3 and 4.4 on that grain gives a teacher a comment and a
PDF for every CAT, and a parent a stack of single-test sheets. A Kenyan school issues
one report card per student per term: each subject across Terms 1, 2, and 3 of the
year, the teacher's remark, and the earlier years' history. A report for one test, for
a class, is the Reports screen's job and already exists.

## Decision

1. **A report is per student per term** (`student_id`, `year`, `term`). The card shows
   each subject's performance level for the terms of the year so far, a
   compact history of earlier years from the student's enrolments, and the remark.
2. **A comment is the remark on that card**, per student per term, not per assessment.
3. **`finalized` stays a per-assessment lock.** What triggers comments and reports is a
   new, explicit term close (`docs/spec/workflow.md`), because the card needs every
   assessment of the term to be final, which no single finalize can know.
4. **`reports-generated` is removed from the assessment lifecycle.** An assessment's
   life ends at `finalized`; whether its term's cards are done is a fact about the
   term, held by the report rows themselves.
5. **What leaves the building.** Parents and students see performance levels.
   A term's level is the level of the subject's mean for that term, worked out from the
   mean percentage, which is never shown. Percentages and class position exist only on screens a teacher uses: Reports and
   the attention lists. Neither appears on a PDF, in a notification, or in anything
   sent to the model.
6. **External terminal examinations** are out of scope. They are set by another body
   and fall after the final term of the final year, when the student leaves, so no card
   depends on them.

## Considered and rejected

- **Keep per-assessment reports and add the term card on top.** Two products and two
  triggers for one need, and the per-assessment one is the one nobody asked for.
- **Trigger on the last finalize of the term.** The system cannot know it is the last:
  a teacher may still add an assessment. An explicit close is the one place that
  decision is made and recorded.
- **Show class position on the card.** Many cards do, but ranking children for their
  families is a decision for the school, not a default for the product. Position stays
  available to the teacher.

## Consequences

- Items 4.3 and 4.4 change grain: comments and reports hang off the term close, not
  off finalize and acceptance of a per-assessment record.
- The assessment `status` enum loses `reports-generated`; existing code and fixtures
  that read it change when the implementation merge requests land.
- The card is cumulative: a later term's card is a new row, never an edit of an
  earlier one, so a closed term stays what it was when it closed.

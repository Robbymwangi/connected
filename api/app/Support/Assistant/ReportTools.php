<?php

namespace App\Support\Assistant;

use App\Models\Subject;
use App\Support\AnalyticsFilters;
use App\Support\AnalyticsQueryService;

/* The assistant's only reach into the school's data (ADR 0013, decision 1): a few read-only tools,
   each a thin wrapper over AnalyticsQueryService, run with the report's own filters, which the
   server fixed from the request. No tool takes a stream, a year, or a school, so the model cannot
   widen the scope; the one argument any tool takes is a student handle, which narrows it. No table
   is described and there is no SQL. Students leave as handles; no name, admission number, or
   school name is in any result (decision 4). */
final class ReportTools
{
    public function __construct(
        private readonly AnalyticsQueryService $analytics,
        private readonly AnalyticsFilters $filters,
        private readonly Handles $handles,
    ) {}

    /** @return list<array<string, mixed>> the tool definitions as the Messages API takes them */
    public static function definitions(): array
    {
        $none = ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];

        return [
            ['name' => 'report_summary', 'description' => 'The headline figures of the report on screen: its scope, the pass mark, pass rate, mean, spread, entry completeness, students per performance level, and net level movement. Percentages are 0 to 100.', 'input_schema' => $none],
            ['name' => 'trend', 'description' => 'Pass rate and mean percentage per assessment in the report\'s scope, oldest first.', 'input_schema' => $none],
            ['name' => 'criterion_breakdown', 'description' => 'Mean percentage per rubric criterion in the report\'s scope, with how many marks each is based on.', 'input_schema' => $none],
            ['name' => 'attention_list', 'description' => 'Students averaging below the pass mark in the report\'s scope, lowest first, as handles (S1, S2) with their mean and latest percentages.', 'input_schema' => $none],
            ['name' => 'decline_list', 'description' => 'Students whose latest percentage fell below their own earlier mean in the report\'s scope, as handles.', 'input_schema' => $none],
            ['name' => 'student_outcomes', 'description' => 'Each student\'s outcome on the one assessment the report is scoped to (empty when it covers several), as handles; give a handle to see one student.', 'input_schema' => [
                'type' => 'object',
                'properties' => ['student' => ['type' => 'string', 'description' => 'A handle such as S3, from an earlier result.']],
                'additionalProperties' => false,
            ]],
        ];
    }

    /**
     * Runs one tool. An unknown tool or a bad argument is an error result the model can read, not
     * an exception: the loop goes on and the model can recover.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $name, array $input): array
    {
        $f = $this->filters;

        return match ($name) {
            'report_summary' => [
                'scope' => [
                    'stream' => $f->stream,
                    'subject' => $f->subjectId === null ? 'all subjects' : Subject::query()->whereKey($f->subjectId)->value('name'),
                    'year' => $f->year,
                    'term' => $f->term ?? 'year to date',
                    'assessment' => $f->assessmentName ?? ($f->assessmentId === null ? 'all assessments' : 'one assessment'),
                ],
                'passMarkPct' => AnalyticsQueryService::PASS_MARK_PCT,
                'passRatePct' => $this->analytics->passRate($f),
                'meanPct' => $this->analytics->meanScore($f),
                'spreadPct' => $this->analytics->scoreSpread($f),
                'entryCompletenessPct' => $this->analytics->entryCompleteness($f),
                'studentsPerLevel' => $this->analytics->performanceLevels($f),
                'netLevelMovement' => $this->analytics->netLevelMovement($f),
            ],
            'trend' => ['assessments' => array_map(fn (array $point) => [
                'subject' => $point['subject'], 'assessment' => $point['label'], 'date' => $point['date'],
                'passRatePct' => $point['passRate'], 'meanPct' => $point['meanScore'],
            ], $this->analytics->trend($f))],
            'criterion_breakdown' => ['criteria' => array_map(fn (array $row) => [
                'criterion' => $row['name'], 'meanPct' => $row['pct'], 'marks' => $row['n'],
            ], $this->analytics->criterionBreakdown($f))],
            'attention_list' => ['passMarkPct' => AnalyticsQueryService::PASS_MARK_PCT, 'students' => array_map(fn (array $row) => [
                'student' => $this->handles->for($row['studentId']), 'meanPct' => $row['meanScore'],
                'latestPct' => $row['latestScore'], 'level' => $row['level'], 'assessmentsScored' => $row['scored'],
            ], $this->analytics->attentionList($f))],
            'decline_list' => ['students' => array_map(fn (array $row) => [
                'student' => $this->handles->for($row['studentId']), 'latestPct' => $row['latestScore'], 'earlierMeanPct' => $row['priorMean'],
            ], $this->analytics->declineList($f))],
            'student_outcomes' => $this->outcomes(isset($input['student']) && is_string($input['student']) ? $input['student'] : null),
            default => ['error' => "There is no tool called {$name}."],
        };
    }

    /** @return array<string, mixed> */
    private function outcomes(?string $handle): array
    {
        $only = $handle === null ? null : $this->handles->studentFor($handle);

        if ($handle !== null && $only === null) {
            return ['error' => "{$handle} is not a student in this conversation."];
        }

        $rows = array_values(array_filter(
            $this->analytics->studentOutcomes($this->filters),
            fn (array $row) => $only === null || $row['studentId'] === $only,
        ));

        return ['outcomes' => array_map(fn (array $row) => [
            'student' => $this->handles->for($row['studentId']), 'status' => $row['status'],
            'total' => $row['total'], 'max' => $row['max'], 'pct' => $row['pct'], 'level' => $row['level'],
        ], $rows)];
    }
}

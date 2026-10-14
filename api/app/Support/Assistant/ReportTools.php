<?php

namespace App\Support\Assistant;

use App\Models\Subject;
use App\Support\AnalyticsFilters;
use App\Support\AnalyticsQueryService;
use Illuminate\Support\Facades\DB;

/* The assistant's only reach into the school's data (ADR 0013, decisions 1 and 7): a closed set of
   read-only tools, each a thin wrapper over AnalyticsQueryService. The report's own filters, which
   the server fixed from the request, are the anchor. Most tools read exactly that scope; a few,
   named in decision 7, look further from it by a rule written here, never by the model's choice:
   the stream's other subjects, the term before, and the other streams of the same grade. No tool
   takes a stream, a year, a subject, or a school, so the model cannot point one anywhere else; the
   one argument any tool takes is a student handle, which narrows it. No table is described and
   there is no SQL. Students leave as handles; no name, admission number, school name, or teacher
   is in any result (decision 4). Comparisons arrive with their differences already worked out, so
   the model quotes them and the grounding check can hold it to them. */
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
            ['name' => 'report_summary', 'description' => 'The headline figures of the report on screen: its scope, the pass mark, pass rate, mean, spread, entry completeness, results per performance level (one result per student per assessment, so not a count of students), and net level movement. Percentages are 0 to 100.', 'input_schema' => $none],
            ['name' => 'trend', 'description' => 'Pass rate and mean percentage per assessment in the report\'s scope, oldest first.', 'input_schema' => $none],
            ['name' => 'criterion_breakdown', 'description' => 'Mean percentage per rubric criterion in the report\'s scope, with how many marks each is based on.', 'input_schema' => $none],
            ['name' => 'attention_list', 'description' => 'Students averaging below the pass mark in the report\'s scope, lowest first, as handles (S1, S2) with their mean and latest percentages.', 'input_schema' => $none],
            ['name' => 'decline_list', 'description' => 'Students whose latest percentage fell below their own earlier mean in the report\'s scope, as handles.', 'input_schema' => $none],
            ['name' => 'student_outcomes', 'description' => 'Each student\'s outcome on the one assessment the report is scoped to (empty when it covers several), as handles; give a handle to see one student.', 'input_schema' => [
                'type' => 'object',
                'properties' => ['student' => ['type' => 'string', 'description' => 'A handle such as S3, from an earlier result.']],
                'additionalProperties' => false,
            ]],
            ['name' => 'class_standings', 'description' => 'Every student in the report\'s scope, highest mean percentage first, as handles, with their latest percentage and level and whether they are in the top, middle, or bottom third of the class. For questions about top performers or where students stand.', 'input_schema' => $none],
            ['name' => 'improvers', 'description' => 'Students whose latest percentage rose at least 10 points above their own earlier mean in the report\'s scope, largest rise first, as handles, with the rise worked out.', 'input_schema' => $none],
            ['name' => 'absences_and_gaps', 'description' => 'For each assessment in the report\'s scope, how many students were scored, absent, or not yet entered; and the students absent from more than one assessment, as handles.', 'input_schema' => $none],
            ['name' => 'student_across_subjects', 'description' => 'One student\'s mean percentage in every subject their stream sat in the report\'s year and term, next to the stream\'s mean in that subject and the gap between them. Answers whether a student struggles in one subject or across all.', 'input_schema' => [
                'type' => 'object',
                'properties' => ['student' => ['type' => 'string', 'description' => 'A handle such as S3, from an earlier result.']],
                'required' => ['student'],
                'additionalProperties' => false,
            ]],
            ['name' => 'struggling_in_several_subjects', 'description' => 'Students averaging below the pass mark in two or more subjects in the report\'s year and term, as handles, with those subjects and their means.', 'input_schema' => $none],
            ['name' => 'subject_breakdown', 'description' => 'Each subject the stream sat in the report\'s year and term, with its mean percentage, pass rate, and results per level (one per student per assessment), highest mean first, and each subject\'s gap from the stream\'s mean across all subjects.', 'input_schema' => $none],
            ['name' => 'term_deviation', 'description' => 'Each subject\'s mean percentage and pass rate in the report\'s term against the term before it in the same year, with the change (the deviation). A term 1 has no earlier term to compare with.', 'input_schema' => $none],
            ['name' => 'stream_comparison', 'description' => 'This stream against the other streams of the same grade in this school, for the report\'s subject, year, and term: each stream\'s mean percentage, pass rate, and number of scored entries, its gap from the grade mean, and its difference from this stream. Says whether the streams sat the same assessments.', 'input_schema' => $none],
            ['name' => 'criterion_across_streams', 'description' => 'Each rubric criterion\'s mean percentage in this stream and in the other streams of the same grade, for the report\'s subject, year, and term, with each stream\'s difference from this one. Shows whether a weak criterion is this class or the whole grade. Needs a report scoped to one subject.', 'input_schema' => $none],
            ['name' => 'gender_breakdown', 'description' => 'Girls and boys as two groups in the report\'s scope: how many students, mean percentage, pass rate, and results per level (one per student per assessment), with the gap between the groups. Group totals only; both groups are withheld when either has fewer than 5 students.', 'input_schema' => $none],
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
        return self::rounded($this->result($name, $input));
    }

    /**
     * Every fractional figure to one decimal, as the report shows it. The model then quotes a
     * figure as given instead of shortening 78.947 itself, and the grounding check compares the
     * answer with exactly what the model was given.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function rounded(array $result): array
    {
        array_walk_recursive($result, function (mixed &$value): void {
            if (is_float($value)) {
                $value = round($value, 1);
            }
        });

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function result(string $name, array $input): array
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
                'resultsPerLevel' => $this->analytics->performanceLevels($f),
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
            'student_outcomes' => $this->outcomes(self::handleIn($input)),
            'class_standings' => $this->standings(),
            'improvers' => ['students' => array_map(fn (array $row) => [
                'student' => $this->handles->for($row['studentId']), 'latestPct' => $row['latestScore'],
                'earlierMeanPct' => $row['priorMean'], 'risePct' => $row['latestScore'] - $row['priorMean'],
            ], $this->analytics->improvementList($f))],
            'absences_and_gaps' => [
                'assessments' => $this->analytics->entryGaps($f),
                'absentMoreThanOnce' => array_map(fn (array $row) => [
                    'student' => $this->handles->for($row['studentId']), 'absences' => $row['absences'],
                ], $this->analytics->repeatedAbsences($f)),
            ],
            'student_across_subjects' => $this->acrossSubjects(self::handleIn($input)),
            'struggling_in_several_subjects' => $this->strugglingInSeveral(),
            'subject_breakdown' => $this->subjectBreakdown(),
            'term_deviation' => $this->termDeviation(),
            'stream_comparison' => $this->streamComparison(),
            'criterion_across_streams' => $this->criteriaAcrossStreams(),
            'gender_breakdown' => $this->genders(),
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

    /** @param  array<string, mixed>  $input */
    private static function handleIn(array $input): ?string
    {
        return isset($input['student']) && is_string($input['student']) ? $input['student'] : null;
    }

    /** @return array<string, mixed> */
    private function standings(): array
    {
        $rows = $this->analytics->studentStandings($this->filters);
        $count = count($rows);

        return ['students' => array_map(fn (array $row, int $index) => [
            'student' => $this->handles->for($row['studentId']), 'meanPct' => $row['meanScore'],
            'latestPct' => $row['latestScore'], 'latestLevel' => $row['level'], 'assessmentsScored' => $row['scored'],
            'third' => match (true) {
                $index < $count / 3 => 'top',
                $index < 2 * $count / 3 => 'middle',
                default => 'bottom',
            },
        ], $rows, array_keys($rows))];
    }

    /* Decision 7's widenings. Each builds its own filters from the report's by one fixed rule; the
       institution never changes, so the global scope still holds them to this school. */

    /** The report's stream, year, and term, every subject: "this term" for a stream. */
    private function streamTerm(): AnalyticsFilters
    {
        $f = $this->filters;

        return new AnalyticsFilters($f->institutionId, $f->stream, null, $f->year, $f->term);
    }

    /** Another stream of the same grade, at the report's subject, year, term, and assessment. */
    private function sister(string $stream, ?string $assessmentName): AnalyticsFilters
    {
        $f = $this->filters;

        return new AnalyticsFilters($f->institutionId, $stream, $f->subjectId, $f->year, $f->term, $assessmentName);
    }

    /** @return array<string, mixed> */
    private function acrossSubjects(?string $handle): array
    {
        $student = $handle === null ? null : $this->handles->studentFor($handle);

        if ($student === null) {
            return ['error' => ($handle ?? 'That').' is not a student in this conversation.'];
        }

        $scope = $this->streamTerm();
        $means = collect($this->analytics->subjectMeansByStudent($scope));

        return ['student' => $handle, 'term' => $scope->term ?? 'year to date', 'subjects' => $means
            ->where('studentId', $student)
            ->map(function (array $row) use ($scope) {
                $streamMean = $this->analytics->meanScore(new AnalyticsFilters($scope->institutionId, $scope->stream, $row['subjectId'], $scope->year, $scope->term));

                return [
                    'subject' => $row['subject'], 'studentMeanPct' => $row['meanScore'], 'assessmentsScored' => $row['scored'],
                    'streamMeanPct' => $streamMean, 'gapFromStreamPct' => $streamMean === null ? null : $row['meanScore'] - $streamMean,
                    'belowPassMark' => $row['meanScore'] < AnalyticsQueryService::PASS_MARK_PCT,
                ];
            })->values()->all()];
    }

    /** @return array<string, mixed> */
    private function strugglingInSeveral(): array
    {
        $below = collect($this->analytics->subjectMeansByStudent($this->streamTerm()))
            ->filter(fn (array $row) => $row['meanScore'] < AnalyticsQueryService::PASS_MARK_PCT)
            ->groupBy('studentId')
            ->filter(fn ($rows) => $rows->count() >= 2)
            ->sortByDesc(fn ($rows) => $rows->count());

        return ['passMarkPct' => AnalyticsQueryService::PASS_MARK_PCT, 'students' => $below->map(fn ($rows, string $studentId) => [
            'student' => $this->handles->for($studentId), 'subjectsBelowPassMark' => $rows->count(),
            'subjects' => $rows->map(fn (array $row) => ['subject' => $row['subject'], 'meanPct' => $row['meanScore']])->values()->all(),
        ])->values()->all()];
    }

    /** @return array<string, mixed> */
    private function subjectBreakdown(): array
    {
        $scope = $this->streamTerm();
        $overall = $this->analytics->meanScore($scope);
        $subjects = array_map(function (array $subject) use ($scope, $overall) {
            $only = new AnalyticsFilters($scope->institutionId, $scope->stream, $subject['id'], $scope->year, $scope->term);
            $mean = $this->analytics->meanScore($only);

            return [
                'subject' => $subject['name'], 'meanPct' => $mean, 'passRatePct' => $this->analytics->passRate($only),
                'resultsPerLevel' => $this->analytics->performanceLevels($only),
                'gapFromStreamMeanPct' => $mean === null || $overall === null ? null : $mean - $overall,
            ];
        }, $this->analytics->scoredSubjects($scope));
        usort($subjects, fn (array $a, array $b) => ($b['meanPct'] ?? -1) <=> ($a['meanPct'] ?? -1));

        return ['term' => $scope->term ?? 'year to date', 'streamMeanPct' => $overall, 'subjects' => $subjects];
    }

    /** @return array<string, mixed> */
    private function termDeviation(): array
    {
        $f = $this->filters;
        $term = $f->term ?? $this->latestScoredTerm();

        if ($term === null) {
            return ['error' => 'There are no scored results in this year yet.'];
        }

        // Only within the year: a stream's name is reused by next year's cohort, so last year's Term 3
        // of this stream is other children.
        if ($term === 1) {
            return ['term' => 1, 'note' => 'Term 1 has no earlier term this year to compare with. Last year\'s results for this stream belong to a different group of students.'];
        }

        $subjects = $f->subjectId === null
            ? $this->analytics->scoredSubjects(new AnalyticsFilters($f->institutionId, $f->stream, null, $f->year, $term))
            : [['id' => $f->subjectId, 'name' => Subject::query()->whereKey($f->subjectId)->value('name')]];

        return ['term' => $term, 'comparedWithTerm' => $term - 1, 'subjects' => array_map(function (array $subject) use ($f, $term) {
            $now = new AnalyticsFilters($f->institutionId, $f->stream, $subject['id'], $f->year, $term);
            $before = new AnalyticsFilters($f->institutionId, $f->stream, $subject['id'], $f->year, $term - 1);
            [$mean, $earlierMean] = [$this->analytics->meanScore($now), $this->analytics->meanScore($before)];
            [$pass, $earlierPass] = [$this->analytics->passRate($now), $this->analytics->passRate($before)];

            return [
                'subject' => $subject['name'],
                'meanPct' => $mean, 'earlierMeanPct' => $earlierMean,
                'meanDeviationPct' => $mean === null || $earlierMean === null ? null : $mean - $earlierMean,
                'passRatePct' => $pass, 'earlierPassRatePct' => $earlierPass,
                'passRateDeviationPct' => $pass === null || $earlierPass === null ? null : $pass - $earlierPass,
            ];
        }, $subjects)];
    }

    private function latestScoredTerm(): ?int
    {
        $terms = array_filter(array_map(
            fn (int $term) => $this->analytics->meanScore(new AnalyticsFilters($this->filters->institutionId, $this->filters->stream, $this->filters->subjectId, $this->filters->year, $term)) === null ? null : $term,
            [3, 2, 1],
        ));

        return $terms === [] ? null : max($terms);
    }

    /**
     * The assessment name every stream is compared on: the report's own, if it is scoped to one,
     * so the streams are held to the same paper.
     */
    private function comparedAssessment(): ?string
    {
        $f = $this->filters;

        if ($f->assessmentId !== null) {
            return DB::table('assessments')->where('institution_id', $f->institutionId)->where('id', $f->assessmentId)->value('name');
        }

        return $f->assessmentName;
    }

    /** @return array<string, mixed> */
    private function streamComparison(): array
    {
        $assessment = $this->comparedAssessment();
        $streams = [$this->filters->stream, ...$this->analytics->sisterStreams($this->filters->institutionId, $this->filters->stream)];

        if (count($streams) === 1) {
            return ['note' => 'This stream is the only one in its grade, so there is nothing to compare it with.'];
        }

        $rows = array_map(function (string $stream) use ($assessment) {
            $scope = $this->sister($stream, $assessment);
            $entries = array_sum($this->analytics->performanceLevels($scope));

            return [
                'stream' => $stream, 'meanPct' => $this->analytics->meanScore($scope), 'passRatePct' => $this->analytics->passRate($scope),
                'scoredEntries' => $entries, 'assessments' => $this->analytics->availableAssessmentNames(
                    new AnalyticsFilters($scope->institutionId, $stream, $scope->subjectId, $scope->year, $scope->term),
                ),
            ];
        }, $streams);

        $scored = array_filter($rows, fn (array $row) => $row['meanPct'] !== null);
        $entries = array_sum(array_column($scored, 'scoredEntries'));
        $gradeMean = $entries === 0 ? null : array_sum(array_map(fn (array $row) => $row['meanPct'] * $row['scoredEntries'], $scored)) / $entries;
        $own = $rows[0];

        return [
            'comparedOn' => $assessment ?? 'every assessment in scope',
            'sameAssessments' => $assessment !== null || count(array_unique(array_map(fn (array $row) => implode('|', $row['assessments']), $rows))) === 1,
            'gradeMeanPct' => $gradeMean,
            'streams' => array_map(fn (array $row) => [
                ...$row,
                'isThisStream' => $row['stream'] === $own['stream'],
                'gapFromGradeMeanPct' => $row['meanPct'] === null || $gradeMean === null ? null : $row['meanPct'] - $gradeMean,
                'meanDifferenceFromThisStreamPct' => $row['meanPct'] === null || $own['meanPct'] === null ? null : $row['meanPct'] - $own['meanPct'],
                'passRateDifferenceFromThisStreamPct' => $row['passRatePct'] === null || $own['passRatePct'] === null ? null : $row['passRatePct'] - $own['passRatePct'],
            ], $rows),
        ];
    }

    /** @return array<string, mixed> */
    private function criteriaAcrossStreams(): array
    {
        if ($this->filters->subjectId === null) {
            return ['error' => 'Criteria belong to one subject; this report covers all subjects.'];
        }

        $assessment = $this->comparedAssessment();
        $streams = [$this->filters->stream, ...$this->analytics->sisterStreams($this->filters->institutionId, $this->filters->stream)];
        $byStream = [];
        foreach ($streams as $stream) {
            foreach ($this->analytics->criterionBreakdown($this->sister($stream, $assessment)) as $row) {
                $byStream[$row['name']][$stream] = $row['pct'];
            }
        }

        return ['comparedOn' => $assessment ?? 'every assessment in scope', 'criteria' => array_map(function (array $means, string $criterion) {
            $own = $means[$this->filters->stream] ?? null;

            return ['criterion' => $criterion, 'streams' => array_map(fn (string $stream, float $mean) => [
                'stream' => $stream, 'meanPct' => $mean, 'isThisStream' => $stream === $this->filters->stream,
                'differenceFromThisStreamPct' => $own === null ? null : $mean - $own,
            ], array_keys($means), $means)];
        }, $byStream, array_keys($byStream))];
    }

    /* Fewer than this many students in a group and its figures are withheld: too few for a fair
       comparison, and close to describing one or two children by their sex. */
    private const SMALLEST_GROUP = 5;

    /** @return array<string, mixed> */
    private function genders(): array
    {
        $groups = $this->analytics->genderBreakdown($this->filters);
        $shown = fn (array $group) => $group['students'] >= self::SMALLEST_GROUP;
        [$girls, $boys] = [$groups['F'], $groups['M']];
        // Both or neither: the report's own totals less one shown group would give the other.
        $both = $shown($girls) && $shown($boys);
        $describe = fn (array $group) => $both
            ? ['students' => $group['students'], 'meanPct' => $group['meanScore'], 'passRatePct' => $group['passRate'], 'resultsPerLevel' => $group['levels']]
            : ['withheld' => 'One group has fewer than '.self::SMALLEST_GROUP.' students; too few to compare.'];

        return [
            'girls' => $describe($girls),
            'boys' => $describe($boys),
            'girlsMinusBoysMeanPct' => $both && $girls['meanScore'] !== null && $boys['meanScore'] !== null ? $girls['meanScore'] - $boys['meanScore'] : null,
            'girlsMinusBoysPassRatePct' => $both && $girls['passRate'] !== null && $boys['passRate'] !== null ? $girls['passRate'] - $boys['passRate'] : null,
        ];
    }
}

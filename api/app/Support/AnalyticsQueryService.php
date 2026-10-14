<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AnalyticsQueryService
{
    public const PASS_MARK_PCT = 50;

    /** @return list<int> */
    public function availableYears(string $institutionId, string $stream, ?string $subjectId): array
    {
        $years = DB::table('assessments as a')
            ->join('classes as cl', function ($join) use ($institutionId): void {
                $join->on('cl.id', '=', 'a.class_id')
                    ->where('cl.institution_id', '=', $institutionId);
            })
            ->where('a.institution_id', $institutionId)
            ->where('cl.stream', $stream)
            ->where('a.status', '<>', 'scheduled')
            ->whereNull('a.deleted_at')
            ->whereNull('cl.deleted_at')
            ->when($subjectId, fn ($query, $id) => $query->where('a.subject_id', $id))
            ->distinct()
            ->orderByDesc('a.year')
            ->pluck('a.year');

        return $years->map(fn ($year): int => (int) $year)->all();
    }

    /** @return list<string> */
    public function availableAssessmentNames(AnalyticsFilters $filters): array
    {
        $withoutName = new AnalyticsFilters(
            $filters->institutionId,
            $filters->stream,
            $filters->subjectId,
            $filters->year,
            $filters->term,
        );

        return $this->scopedAssessments($withoutName)
            ->select('a.name as assessment_name')
            ->distinct()
            ->orderBy('assessment_name')
            ->pluck('assessment_name')
            ->all();
    }

    /** @return list<array{studentId: string, studentName: string, classId: string, status: string, total: ?int, max: ?int, pct: ?float, level: ?string}> */
    public function studentOutcomes(AnalyticsFilters $filters): array
    {
        if ($filters->assessmentId === null) {
            return [];
        }

        return DB::query()->fromSub($this->outcomes($filters), 'outcomes')
            ->select([
                'student_id', 'student_name', 'class_id', 'outcome_status', 'total', 'max', 'pct', 'level',
            ])
            ->orderBy('student_name')
            ->get()
            ->map(fn (object $row): array => [
                'studentId' => $row->student_id,
                'studentName' => $row->student_name,
                'classId' => $row->class_id,
                'status' => $row->outcome_status,
                'total' => $row->total === null ? null : (int) $row->total,
                'max' => $row->max === null ? null : (int) $row->max,
                'pct' => $row->pct === null ? null : (float) $row->pct,
                'level' => $row->level,
            ])
            ->all();
    }

    public function passRate(AnalyticsFilters $filters): ?float
    {
        $row = $this->scoredOutcomes($filters)
            ->selectRaw('count(*) as scored_count, sum(case when pct >= ? then 1 else 0 end) as passed_count', [self::PASS_MARK_PCT])
            ->first();

        if ((int) $row->scored_count === 0) {
            return null;
        }

        return (float) $row->passed_count / (int) $row->scored_count * 100;
    }

    public function meanScore(AnalyticsFilters $filters): ?float
    {
        $mean = $this->scoredOutcomes($filters)->avg('pct');

        return $mean === null ? null : (float) $mean;
    }

    /** @return array{min: float, max: float, iqr: float}|null */
    public function scoreSpread(AnalyticsFilters $filters): ?array
    {
        $row = $this->scoredOutcomes($filters)
            ->selectRaw(
                'count(*) as scored_count, min(pct) as minimum, max(pct) as maximum, '.
                '(percentile_cont(0.75) within group (order by pct) - '.
                'percentile_cont(0.25) within group (order by pct)) as iqr'
            )
            ->first();

        if ((int) $row->scored_count === 0) {
            return null;
        }

        return [
            'min' => (float) $row->minimum,
            'max' => (float) $row->maximum,
            'iqr' => (float) $row->iqr,
        ];
    }

    public function entryCompleteness(AnalyticsFilters $filters): ?float
    {
        $row = DB::query()->fromSub($this->outcomes($filters), 'outcomes')
            ->selectRaw(
                'count(*) as expected_count, '.
                'sum(case when outcome_status in (?, ?) then 1 else 0 end) as entered_count',
                ['scored', 'absent']
            )
            ->first();

        if ((int) $row->expected_count === 0) {
            return null;
        }

        return (float) $row->entered_count / (int) $row->expected_count * 100;
    }

    /** @return array{EE: int, ME: int, AE: int, BE: int} */
    public function performanceLevels(AnalyticsFilters $filters): array
    {
        $counts = $this->scoredOutcomes($filters)
            ->select('level')
            ->selectRaw('count(*) as count')
            ->groupBy('level')
            ->pluck('count', 'level');

        return [
            'EE' => (int) ($counts['EE'] ?? 0),
            'ME' => (int) ($counts['ME'] ?? 0),
            'AE' => (int) ($counts['AE'] ?? 0),
            'BE' => (int) ($counts['BE'] ?? 0),
        ];
    }

    /** @return list<array{bin: string, count: int}> */
    public function histogram(AnalyticsFilters $filters): array
    {
        $counts = $this->scoredOutcomes($filters)
            ->selectRaw('least(9, floor(pct / 10)::integer) as bin_index, count(*) as count')
            ->groupBy('bin_index')
            ->pluck('count', 'bin_index');

        $bins = [];
        for ($index = 0; $index < 10; $index++) {
            $start = $index * 10;
            $end = $index === 9 ? 100 : $start + 9;
            $bins[] = [
                'bin' => "{$start}-{$end}",
                'count' => (int) ($counts[$index] ?? 0),
            ];
        }

        return $bins;
    }

    /** @return list<array{key: string, subject: string, label: string, date: string, passRate: ?float, meanScore: ?float}> */
    public function trend(AnalyticsFilters $filters): array
    {
        $rows = DB::query()->fromSub($this->outcomes($filters), 'outcomes')
            ->select([
                'assessment_id', 'assessment_name', 'subject_name', 'term', 'assessment_date',
            ])
            ->selectRaw(
                'count(*) filter (where outcome_status = ?) as scored_count, '.
                'sum(case when outcome_status = ? and pct >= ? then 1 else 0 end) as passed_count, '.
                'avg(case when outcome_status = ? then pct end) as mean_pct',
                ['scored', 'scored', self::PASS_MARK_PCT, 'scored']
            )
            ->groupBy('assessment_id', 'assessment_name', 'subject_name', 'term', 'assessment_date')
            ->orderBy('assessment_date')
            ->orderBy('subject_name')
            ->orderBy('assessment_name')
            ->orderBy('term')
            ->get();

        return $rows->map(function (object $row): array {
            $scoredCount = (int) $row->scored_count;

            return [
                'key' => "{$row->subject_name}|{$row->assessment_name}|{$row->term}",
                'subject' => $row->subject_name,
                'label' => "{$row->assessment_name}, {$row->term}",
                'date' => (string) $row->assessment_date,
                'passRate' => $scoredCount === 0 ? null : (float) $row->passed_count / $scoredCount * 100,
                'meanScore' => $row->mean_pct === null ? null : (float) $row->mean_pct,
            ];
        })->all();
    }

    /** @return list<array{name: string, pct: float, n: int}> */
    public function criterionBreakdown(AnalyticsFilters $filters): array
    {
        if ($filters->subjectId === null) {
            return [];
        }

        return DB::table('marks as m')
            ->joinSub($this->scopedAssessments($filters), 'assessments', 'assessments.id', '=', 'm.assessment_id')
            ->join('criteria as c', function ($join) use ($filters): void {
                $join->on('c.id', '=', 'm.criterion_id')
                    ->where('c.institution_id', '=', $filters->institutionId);
            })
            ->where('m.institution_id', $filters->institutionId)
            ->whereNull('m.deleted_at')
            ->whereNull('c.deleted_at')
            ->where('m.mark_kind', 'score')
            ->select('c.name')
            ->selectRaw('avg(m.score::double precision / c.max_score * 100) as pct, count(*) as n')
            ->groupBy('c.name')
            ->orderBy('c.name')
            ->get()
            ->map(fn (object $row): array => [
                'name' => $row->name,
                'pct' => (float) $row->pct,
                'n' => (int) $row->n,
            ])
            ->all();
    }

    /** @return list<array{studentId: string, meanScore: float, latestScore: float, level: string, scored: int}> */
    public function attentionList(AnalyticsFilters $filters): array
    {
        $ranked = $this->scoredOutcomes($filters)
            ->select('student_id', 'pct', 'level', 'assessment_date', 'assessment_id')
            ->selectRaw(
                'avg(pct) over (partition by student_id) as mean_pct, '.
                'count(*) over (partition by student_id) as scored_count, '.
                'row_number() over (partition by student_id order by assessment_date desc, assessment_id desc) as latest_row'
            );

        return DB::query()->fromSub($ranked, 'ranked')
            ->where('latest_row', 1)
            ->where('mean_pct', '<', self::PASS_MARK_PCT)
            ->orderBy('mean_pct')
            ->get()
            ->map(fn (object $row): array => [
                'studentId' => $row->student_id,
                'meanScore' => (float) $row->mean_pct,
                'latestScore' => (float) $row->pct,
                'level' => $row->level,
                'scored' => (int) $row->scored_count,
            ])
            ->all();
    }

    /** @return list<array{studentId: string, latestScore: float, priorMean: float}> */
    public function declineList(AnalyticsFilters $filters): array
    {
        $ranked = $this->scoredOutcomes($filters)
            ->select('student_id', 'pct', 'assessment_date', 'assessment_id')
            ->selectRaw(
                'avg(pct) over (partition by student_id order by assessment_date, assessment_id '.
                'rows between unbounded preceding and 1 preceding) as prior_mean, '.
                'count(*) over (partition by student_id) as scored_count, '.
                'row_number() over (partition by student_id order by assessment_date desc, assessment_id desc) as latest_row'
            );

        return DB::query()->fromSub($ranked, 'ranked')
            ->where('latest_row', 1)
            ->where('scored_count', '>=', 2)
            ->whereRaw('pct <= prior_mean - 10')
            ->orderBy('student_id')
            ->get()
            ->map(fn (object $row): array => [
                'studentId' => $row->student_id,
                'latestScore' => (float) $row->pct,
                'priorMean' => (float) $row->prior_mean,
            ])
            ->all();
    }

    /**
     * Every student with a scored outcome in scope, highest mean first: the whole class, where the
     * attention list is only its tail.
     *
     * @return list<array{studentId: string, meanScore: float, latestScore: float, level: string, scored: int}>
     */
    public function studentStandings(AnalyticsFilters $filters): array
    {
        $ranked = $this->scoredOutcomes($filters)
            ->select('student_id', 'pct', 'level', 'assessment_date', 'assessment_id')
            ->selectRaw(
                'avg(pct) over (partition by student_id) as mean_pct, '.
                'count(*) over (partition by student_id) as scored_count, '.
                'row_number() over (partition by student_id order by assessment_date desc, assessment_id desc) as latest_row'
            );

        return DB::query()->fromSub($ranked, 'ranked')
            ->where('latest_row', 1)
            ->orderByDesc('mean_pct')
            ->orderBy('student_id')
            ->get()
            ->map(fn (object $row): array => [
                'studentId' => $row->student_id,
                'meanScore' => (float) $row->mean_pct,
                'latestScore' => (float) $row->pct,
                'level' => $row->level,
                'scored' => (int) $row->scored_count,
            ])
            ->all();
    }

    /**
     * The mirror of the decline list: students whose latest percentage rose at least ten points
     * above their own earlier mean.
     *
     * @return list<array{studentId: string, latestScore: float, priorMean: float}>
     */
    public function improvementList(AnalyticsFilters $filters): array
    {
        $ranked = $this->scoredOutcomes($filters)
            ->select('student_id', 'pct', 'assessment_date', 'assessment_id')
            ->selectRaw(
                'avg(pct) over (partition by student_id order by assessment_date, assessment_id '.
                'rows between unbounded preceding and 1 preceding) as prior_mean, '.
                'count(*) over (partition by student_id) as scored_count, '.
                'row_number() over (partition by student_id order by assessment_date desc, assessment_id desc) as latest_row'
            );

        return DB::query()->fromSub($ranked, 'ranked')
            ->where('latest_row', 1)
            ->where('scored_count', '>=', 2)
            ->whereRaw('pct >= prior_mean + 10')
            ->orderByRaw('pct - prior_mean desc')
            ->orderBy('student_id')
            ->get()
            ->map(fn (object $row): array => [
                'studentId' => $row->student_id,
                'latestScore' => (float) $row->pct,
                'priorMean' => (float) $row->prior_mean,
            ])
            ->all();
    }

    /**
     * Per assessment in scope, how many enrolled students were scored, absent, or not yet entered.
     *
     * @return list<array{subject: string, assessment: string, date: string, expected: int, scored: int, absent: int, missing: int}>
     */
    public function entryGaps(AnalyticsFilters $filters): array
    {
        return DB::query()->fromSub($this->outcomes($filters), 'outcomes')
            ->select('assessment_id', 'assessment_name', 'subject_name', 'assessment_date')
            ->selectRaw(
                'count(*) as expected_count, '.
                'count(*) filter (where outcome_status = ?) as scored_count, '.
                'count(*) filter (where outcome_status = ?) as absent_count, '.
                'count(*) filter (where outcome_status = ?) as missing_count',
                ['scored', 'absent', 'missing']
            )
            ->groupBy('assessment_id', 'assessment_name', 'subject_name', 'assessment_date')
            ->orderBy('assessment_date')
            ->orderBy('subject_name')
            ->orderBy('assessment_name')
            ->get()
            ->map(fn (object $row): array => [
                'subject' => $row->subject_name,
                'assessment' => $row->assessment_name,
                'date' => (string) $row->assessment_date,
                'expected' => (int) $row->expected_count,
                'scored' => (int) $row->scored_count,
                'absent' => (int) $row->absent_count,
                'missing' => (int) $row->missing_count,
            ])
            ->all();
    }

    /**
     * Students marked absent on more than one assessment in scope, most absences first.
     *
     * @return list<array{studentId: string, absences: int}>
     */
    public function repeatedAbsences(AnalyticsFilters $filters): array
    {
        return DB::query()->fromSub($this->outcomes($filters), 'outcomes')
            ->where('outcome_status', 'absent')
            ->select('student_id')
            ->selectRaw('count(*) as absences')
            ->groupBy('student_id')
            ->havingRaw('count(*) >= 2')
            ->orderByDesc('absences')
            ->orderBy('student_id')
            ->get()
            ->map(fn (object $row): array => ['studentId' => $row->student_id, 'absences' => (int) $row->absences])
            ->all();
    }

    /**
     * Each student's mean percentage per subject in scope; the filters' subject is the caller's
     * to clear when every subject is wanted.
     *
     * @return list<array{studentId: string, subjectId: string, subject: string, meanScore: float, scored: int}>
     */
    public function subjectMeansByStudent(AnalyticsFilters $filters): array
    {
        return $this->scoredOutcomes($filters)
            ->select('student_id', 'subject_id', 'subject_name')
            ->selectRaw('avg(pct) as mean_pct, count(*) as scored_count')
            ->groupBy('student_id', 'subject_id', 'subject_name')
            ->orderBy('student_id')
            ->orderBy('subject_name')
            ->get()
            ->map(fn (object $row): array => [
                'studentId' => $row->student_id,
                'subjectId' => $row->subject_id,
                'subject' => $row->subject_name,
                'meanScore' => (float) $row->mean_pct,
                'scored' => (int) $row->scored_count,
            ])
            ->all();
    }

    /**
     * The subjects with at least one scored outcome in scope, by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function scoredSubjects(AnalyticsFilters $filters): array
    {
        return $this->scoredOutcomes($filters)
            ->select('subject_id', 'subject_name')
            ->distinct()
            ->orderBy('subject_name')
            ->get()
            ->map(fn (object $row): array => ['id' => $row->subject_id, 'name' => $row->subject_name])
            ->all();
    }

    /**
     * Group figures for girls and for boys: how many students, their mean, pass rate, and levels.
     * Totals only; no row names a student.
     *
     * @return array<'F'|'M', array{students: int, meanScore: ?float, passRate: ?float, levels: array{EE: int, ME: int, AE: int, BE: int}}>
     */
    public function genderBreakdown(AnalyticsFilters $filters): array
    {
        $rows = $this->scoredOutcomes($filters)
            ->select('gender')
            ->selectRaw(
                'count(distinct student_id) as student_count, count(*) as scored_count, avg(pct) as mean_pct, '.
                'sum(case when pct >= ? then 1 else 0 end) as passed_count, '.
                "count(*) filter (where level = 'EE') as ee, count(*) filter (where level = 'ME') as me, ".
                "count(*) filter (where level = 'AE') as ae, count(*) filter (where level = 'BE') as be",
                [self::PASS_MARK_PCT]
            )
            ->groupBy('gender')
            ->get()
            ->keyBy('gender');

        $groups = [];
        foreach (['F', 'M'] as $gender) {
            $row = $rows[$gender] ?? null;
            $scored = $row === null ? 0 : (int) $row->scored_count;
            $groups[$gender] = [
                'students' => $row === null ? 0 : (int) $row->student_count,
                'meanScore' => $scored === 0 ? null : (float) $row->mean_pct,
                'passRate' => $scored === 0 ? null : (float) $row->passed_count / $scored * 100,
                'levels' => [
                    'EE' => (int) ($row->ee ?? 0), 'ME' => (int) ($row->me ?? 0),
                    'AE' => (int) ($row->ae ?? 0), 'BE' => (int) ($row->be ?? 0),
                ],
            ];
        }

        return $groups;
    }

    /**
     * The other streams of the same grade as this one, in this school: the only streams a
     * comparison may reach.
     *
     * @return list<string>
     */
    public function sisterStreams(string $institutionId, string $stream): array
    {
        $grades = DB::table('classes')
            ->where('institution_id', $institutionId)
            ->where('stream', $stream)
            ->whereNull('deleted_at')
            ->select('grade');

        return DB::table('classes')
            ->where('institution_id', $institutionId)
            ->whereIn('grade', $grades)
            ->where('stream', '<>', $stream)
            ->whereNull('deleted_at')
            ->distinct()
            ->orderBy('stream')
            ->pluck('stream')
            ->all();
    }

    public function netLevelMovement(AnalyticsFilters $filters): int
    {
        $steps = $this->scoredOutcomes($filters)
            ->select('student_id', 'level_rank', 'assessment_date', 'assessment_id')
            ->selectRaw(
                'lag(level_rank) over (partition by student_id order by assessment_date, assessment_id) as previous_rank'
            );

        $movement = DB::query()->fromSub($steps, 'steps')
            ->selectRaw(
                'coalesce(sum(case '.
                'when level_rank > previous_rank then 1 '.
                'when level_rank < previous_rank then -1 else 0 end), 0) as movement'
            )
            ->value('movement');

        return (int) $movement;
    }

    private function scopedAssessments(AnalyticsFilters $filters): Builder
    {
        $query = DB::table('assessments as a')
            ->join('classes as cl', function ($join) use ($filters): void {
                $join->on('cl.id', '=', 'a.class_id')
                    ->where('cl.institution_id', '=', $filters->institutionId);
            })
            ->join('subjects as s', function ($join) use ($filters): void {
                $join->on('s.id', '=', 'a.subject_id')
                    ->where('s.institution_id', '=', $filters->institutionId);
            })
            ->where('a.institution_id', $filters->institutionId)
            ->where('cl.stream', $filters->stream)
            ->where('a.year', $filters->year)
            ->where('a.status', '<>', 'scheduled')
            ->whereNull('a.deleted_at')
            ->whereNull('cl.deleted_at')
            ->whereNull('s.deleted_at')
            ->select([
                'a.id', 'a.class_id', 'a.subject_id', 'a.name', 'a.term', 'a.year', 'a.date',
                's.name as subject_name',
            ]);

        if ($filters->subjectId !== null) {
            $query->where('a.subject_id', $filters->subjectId);
        }

        if ($filters->term !== null) {
            $query->where('a.term', $filters->term);
        }

        if ($filters->assessmentName !== null && $filters->assessmentName !== '') {
            $query->where('a.name', $filters->assessmentName);
        }

        if ($filters->assessmentId !== null) {
            $query->where('a.id', $filters->assessmentId);
        }

        return $query;
    }

    private function outcomes(AnalyticsFilters $filters): Builder
    {
        $rubrics = DB::table('criteria')
            ->where('institution_id', $filters->institutionId)
            ->whereNull('deleted_at')
            ->select('subject_id')
            ->selectRaw('count(*) as criterion_count, sum(max_score) as max_score')
            ->groupBy('subject_id');

        $grids = DB::table('marks as m')
            ->join('criteria as c', function ($join) use ($filters): void {
                $join->on('c.id', '=', 'm.criterion_id')
                    ->where('c.institution_id', '=', $filters->institutionId);
            })
            ->where('m.institution_id', $filters->institutionId)
            ->whereNull('m.deleted_at')
            ->whereNull('c.deleted_at')
            ->select('m.assessment_id', 'm.student_id')
            ->selectRaw(
                "sum(case when m.mark_kind = 'score' then 1 else 0 end) as score_count, ".
                "sum(case when m.mark_kind = 'absent' then 1 else 0 end) as absent_count, ".
                "sum(case when m.mark_kind = 'score' then m.score else 0 end) as score_total"
            )
            ->groupBy('m.assessment_id', 'm.student_id');

        $results = DB::table('results')
            ->where('institution_id', $filters->institutionId)
            ->whereNull('deleted_at')
            ->select('id', 'assessment_id', 'student_id', 'total', 'max');

        $assessmentRows = $this->scopedAssessments($filters);

        // A row is levelled when nothing in it is absent and either its grid is complete or a result
        // answers for it; the share is then of whichever of the two supplied the total.
        $complete = 'grid.score_count = rubric.criterion_count and rubric.criterion_count > 0';
        $counted = "coalesce(grid.absent_count, 0) = 0 and ({$complete} or result.id is not null)";
        $share = "(case when {$complete} then grid.score_total else result.total end)::double precision ".
            "/ nullif(case when {$complete} then rubric.max_score else result.max end, 0)";

        return DB::query()->fromSub($assessmentRows, 'a')
            ->join('enrolments as e', function ($join) use ($filters): void {
                $join->on('e.class_id', '=', 'a.class_id')
                    ->on('e.year', '=', 'a.year')
                    ->where('e.institution_id', '=', $filters->institutionId);
            })
            ->join('students as st', function ($join) use ($filters): void {
                $join->on('st.id', '=', 'e.student_id')
                    ->where('st.institution_id', '=', $filters->institutionId);
            })
            ->leftJoinSub($rubrics, 'rubric', 'rubric.subject_id', '=', 'a.subject_id')
            ->leftJoinSub($grids, 'grid', function ($join): void {
                $join->on('grid.assessment_id', '=', 'a.id')
                    ->on('grid.student_id', '=', 'e.student_id');
            })
            ->leftJoinSub($results, 'result', function ($join): void {
                $join->on('result.assessment_id', '=', 'a.id')
                    ->on('result.student_id', '=', 'e.student_id');
            })
            ->whereNull('e.deleted_at')
            ->whereNull('st.deleted_at')
            ->select([
                'a.id as assessment_id',
                'a.class_id as class_id',
                'a.name as assessment_name',
                'a.term',
                'a.date as assessment_date',
                'a.subject_id',
                'a.subject_name',
                'e.student_id',
                'st.name as student_name',
                'st.gender',
                'rubric.criterion_count',
                'rubric.max_score',
                'grid.score_count',
                'grid.absent_count',
                'grid.score_total',
                'result.id as result_id',
                'result.total as result_total',
                'result.max as result_max',
            ])
            ->selectRaw(
                "case when coalesce(grid.absent_count, 0) > 0 then 'absent' ".
                "when coalesce(grid.score_count, 0) = rubric.criterion_count and rubric.criterion_count > 0 then 'scored' ".
                "when result.id is not null then 'scored' else 'missing' end as outcome_status"
            )
            ->selectRaw(
                'case when coalesce(grid.absent_count, 0) = 0 and grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 '.
                'then grid.score_total when result.id is not null and coalesce(grid.absent_count, 0) = 0 then result.total end as total'
            )
            ->selectRaw(
                'case when coalesce(grid.absent_count, 0) = 0 and grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 '.
                'then rubric.max_score when result.id is not null and coalesce(grid.absent_count, 0) = 0 then result.max end as max'
            )
            ->selectRaw(
                'case when coalesce(grid.absent_count, 0) = 0 and (grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 or result.id is not null) '.
                'then (case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then grid.score_total else result.total end)::double precision '.
                '/ nullif(case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then rubric.max_score else result.max end, 0) * 100 end as pct'
            )
            ->selectRaw(
                "case when {$counted} then ".PerformanceLevel::sqlCase($share).' end as level, '.
                "case when {$counted} then ".PerformanceLevel::sqlRank($share).' end as level_rank'
            );
    }

    private function scoredOutcomes(AnalyticsFilters $filters): Builder
    {
        return DB::query()->fromSub($this->outcomes($filters), 'outcomes')
            ->where('outcome_status', 'scored');
    }
}

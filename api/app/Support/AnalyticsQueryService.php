<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AnalyticsQueryService
{
    private const PASS_MARK_PCT = 50;

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
            ->select('id', 'assessment_id', 'student_id', 'total');

        $assessmentRows = $this->scopedAssessments($filters);

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
                'a.name as assessment_name',
                'a.term',
                'a.date as assessment_date',
                'a.subject_name',
                'e.student_id',
                'st.name as student_name',
                'rubric.criterion_count',
                'rubric.max_score',
                'grid.score_count',
                'grid.absent_count',
                'grid.score_total',
                'result.id as result_id',
                'result.total as result_total',
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
                'case when coalesce(grid.absent_count, 0) = 0 and (grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 or result.id is not null) '.
                'then (case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then grid.score_total else result.total end)::double precision '.
                '/ nullif(rubric.max_score, 0) * 100 end as pct'
            )
            ->selectRaw(
                "case when coalesce(grid.absent_count, 0) = 0 and (grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 or result.id is not null) then ".
                'case when (case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then grid.score_total else result.total end)::double precision / nullif(rubric.max_score, 0) >= 0.8 then \'EE\' '.
                'when (case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then grid.score_total else result.total end)::double precision / nullif(rubric.max_score, 0) >= 0.6 then \'ME\' '.
                'when (case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then grid.score_total else result.total end)::double precision / nullif(rubric.max_score, 0) >= 0.4 then \'AE\' else \'BE\' end end as level, '.
                "case when coalesce(grid.absent_count, 0) = 0 and (grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 or result.id is not null) then ".
                'case when (case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then grid.score_total else result.total end)::double precision / nullif(rubric.max_score, 0) >= 0.8 then 3 '.
                'when (case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then grid.score_total else result.total end)::double precision / nullif(rubric.max_score, 0) >= 0.6 then 2 '.
                'when (case when grid.score_count = rubric.criterion_count and rubric.criterion_count > 0 then grid.score_total else result.total end)::double precision / nullif(rubric.max_score, 0) >= 0.4 then 1 else 0 end end as level_rank'
            );
    }

    private function scoredOutcomes(AnalyticsFilters $filters): Builder
    {
        return DB::query()->fromSub($this->outcomes($filters), 'outcomes')
            ->where('outcome_status', 'scored');
    }
}
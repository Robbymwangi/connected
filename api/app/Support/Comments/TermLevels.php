<?php

namespace App\Support\Comments;

use App\Models\Result;
use App\Support\PerformanceLevel;
use Illuminate\Support\Collection;

/* A student's performance level per subject per term: the level of the mean of that term's
   assessment percentages (total over max from each live result of a live assessment), so every
   sitting weighs the same whatever its maximum (ADR 0012 decision 5; agreed for 4.3). A sitting
   the student missed entirely has no result and drops out; a partly absent one counts with its
   reduced max (docs/spec/data-model.md, results). The comment prompt and the report card (4.4)
   both read this, so the remark and the card cannot disagree. The mean percentage itself never
   leaves this class. */
final class TermLevels
{
    /**
     * @param  list<int>  $years
     * @return list<array{subject: string, year: int, term: int, level: PerformanceLevel}>
     */
    public static function forStudent(string $studentId, array $years): array
    {
        return self::termShares($studentId, $years)->map(fn (object $row) => [
            'subject' => (string) $row->subject,
            'year' => (int) $row->year,
            'term' => (int) $row->term,
            'level' => PerformanceLevel::forShare((float) $row->mean_share),
        ])->all();
    }

    /**
     * A year's level per subject, for the report card's history of earlier years: the level of the
     * mean of that year's term means, so each term weighs the same, as each sitting does within a term.
     *
     * @param  list<int>  $years
     * @return list<array{subject: string, year: int, level: PerformanceLevel}>
     */
    public static function forStudentByYear(string $studentId, array $years): array
    {
        return self::termShares($studentId, $years)
            ->groupBy(fn (object $row) => $row->subject."\0".$row->year)
            ->map(fn (Collection $terms) => [
                'subject' => (string) $terms->first()->subject,
                'year' => (int) $terms->first()->year,
                'level' => PerformanceLevel::forShare((float) $terms->avg('mean_share')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $years
     * @return Collection<int, object{subject: string, year: int, term: int, mean_share: float}>
     */
    private static function termShares(string $studentId, array $years): Collection
    {
        return Result::query()
            ->join('assessments', 'assessments.id', '=', 'results.assessment_id')
            ->join('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->where('results.student_id', $studentId)
            ->whereIn('assessments.year', $years)
            ->whereNull('assessments.deleted_at')
            ->whereNull('subjects.deleted_at')
            ->groupBy('subjects.name', 'assessments.year', 'assessments.term')
            ->orderBy('subjects.name')
            ->orderBy('assessments.year')
            ->orderBy('assessments.term')
            ->selectRaw('subjects.name as subject, assessments.year, assessments.term')
            ->selectRaw('avg(results.total::double precision / results.max) as mean_share')
            ->toBase()
            ->get();
    }
}

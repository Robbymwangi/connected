<?php

namespace App\Support\Reports;

use App\Models\Comment;
use App\Models\Enrolment;
use App\Models\TermClosure;
use App\Support\Comments\TermLevels;
use App\Support\PerformanceLevel;

/* What a student's report card for one term says (ADR 0012, docs/spec/workflow.md, Report cards):
   each subject's performance level for Terms 1, 2, and 3 of the year, a term not closed for the
   student by then being blank; a compact history of earlier years from the student's enrolments;
   the accepted remark; and the school and student identifiers. Levels come from TermLevels, the
   same place the comment prompt reads, so the remark and the card cannot disagree. There is no
   field here for a percentage, a position, a rank, or a class size, so the template cannot print one. */
final class ReportCard
{
    /**
     * @return array{
     *     school: string,
     *     student: string,
     *     stream: string,
     *     year: int,
     *     term: int,
     *     subjects: list<array{subject: string, terms: array<int, ?PerformanceLevel>}>,
     *     history: list<array{year: int, stream: ?string, subjects: list<array{subject: string, level: PerformanceLevel}>}>,
     *     remark: string,
     *     levels: list<PerformanceLevel>,
     * }
     */
    public static function for(Comment $comment, TermClosure $closure): array
    {
        $comment->loadMissing(['student', 'institution']);
        $closure->loadMissing('schoolClass');
        $year = (int) $comment->year;
        $term = (int) $comment->term;

        return [
            'school' => (string) $comment->institution->name,
            'student' => (string) $comment->student->name,
            'stream' => self::streamName($closure->schoolClass?->grade, $closure->schoolClass?->stream),
            'year' => $year,
            'term' => $term,
            'subjects' => self::subjects($comment->student_id, $year, $term),
            'history' => self::history($comment->student_id, $year),
            'remark' => (string) $comment->body,
            'levels' => PerformanceLevel::cases(),
        ];
    }

    /**
     * Terms of the year up to this one that are closed for a stream the student was enrolled in that
     * year; any other term is blank, including a later one.
     *
     * @return list<array{subject: string, terms: array<int, ?PerformanceLevel>}>
     */
    private static function subjects(string $studentId, int $year, int $term): array
    {
        $closed = TermClosure::query()
            ->where('year', $year)
            ->where('term', '<=', $term)
            ->whereIn('class_id', Enrolment::query()->where('student_id', $studentId)->where('year', $year)->select('class_id'))
            ->pluck('term')
            ->map(fn ($closedTerm) => (int) $closedTerm)
            ->all();

        return collect(TermLevels::forStudent($studentId, [$year]))
            ->filter(fn (array $row) => in_array($row['term'], $closed, true))
            ->groupBy('subject')
            ->map(fn ($rows, string $subject) => [
                'subject' => $subject,
                'terms' => array_replace([1 => null, 2 => null, 3 => null], $rows->pluck('level', 'term')->all()),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{year: int, stream: ?string, subjects: list<array{subject: string, level: PerformanceLevel}>}> */
    private static function history(string $studentId, int $year): array
    {
        $enrolments = Enrolment::query()
            ->with(['schoolClass' => fn ($query) => $query->withTrashed()])
            ->where('student_id', $studentId)
            ->where('year', '<', $year)
            ->orderBy('year')
            ->get();

        $levels = collect(TermLevels::forStudentByYear($studentId, $enrolments->pluck('year')->map(fn ($y) => (int) $y)->unique()->values()->all()))
            ->groupBy('year');

        return $enrolments
            ->unique('year')
            ->map(fn (Enrolment $enrolment) => [
                'year' => (int) $enrolment->year,
                'stream' => $enrolment->schoolClass === null ? null : self::streamName($enrolment->schoolClass->grade, $enrolment->schoolClass->stream),
                'subjects' => ($levels[(int) $enrolment->year] ?? collect())
                    ->map(fn (array $row) => ['subject' => $row['subject'], 'level' => $row['level']])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private static function streamName(mixed $grade, mixed $stream): string
    {
        return trim("Grade {$grade} {$stream}");
    }
}

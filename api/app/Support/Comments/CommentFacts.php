<?php

namespace App\Support\Comments;

use App\Support\PerformanceLevel;

/* Everything a comment prompt may know about a student, and nothing more: the term the remark is
   for, and for each subject the performance level in that term and in the earlier terms of this
   year and last year. There is deliberately no field for a name, an admission number, the school,
   a percentage, or a class position (docs/spec/workflow.md, Comments: what the model is sent), so
   the privacy boundary is the type, not a convention a caller has to remember. */
final class CommentFacts
{
    /**
     * @param  array<string, list<array{year: int, term: int, level: PerformanceLevel}>>  $subjects  each subject's levels, oldest first
     */
    private function __construct(
        public readonly int $year,
        public readonly int $term,
        public readonly array $subjects,
    ) {}

    public static function for(string $studentId, int $year, int $term): self
    {
        $subjects = [];

        foreach (TermLevels::forStudent($studentId, [$year - 1, $year]) as $row) {
            if ($row['year'] === $year && $row['term'] > $term) {
                continue;
            }

            $subjects[$row['subject']][] = ['year' => $row['year'], 'term' => $row['term'], 'level' => $row['level']];
        }

        return new self($year, $term, $subjects);
    }

    /** @return list<array{subject: string, now: ?PerformanceLevel, before: list<array{year: int, term: int, level: PerformanceLevel}>}> */
    public function bySubject(): array
    {
        $out = [];

        foreach ($this->subjects as $subject => $levels) {
            $now = null;
            $before = [];

            foreach ($levels as $entry) {
                if ($entry['year'] === $this->year && $entry['term'] === $this->term) {
                    $now = $entry['level'];
                } else {
                    $before[] = $entry;
                }
            }

            $out[] = ['subject' => $subject, 'now' => $now, 'before' => $before];
        }

        return $out;
    }
}

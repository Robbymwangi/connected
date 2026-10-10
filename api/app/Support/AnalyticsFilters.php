<?php

namespace App\Support;

final readonly class AnalyticsFilters
{
    public function __construct(
        public string $institutionId,
        public string $stream,
        public ?string $subjectId,
        public int $year,
        public ?int $term = null,
        public ?string $assessmentName = null,
        public ?string $assessmentId = null,
    ) {}
}

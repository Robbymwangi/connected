<?php

use App\Support\PerformanceLevel;

/* The thresholds of frontend/src/lib/grading.ts, 80, 60, and 40 percent of the maximum, each
   inclusive at its lower bound. */
test('each level starts exactly at its threshold', function (int $total, int $max, PerformanceLevel $level) {
    expect(PerformanceLevel::fromShare($total, $max))->toBe($level);
})->with([
    '80 of 100' => [80, 100, PerformanceLevel::EE],
    '48 of 60' => [48, 60, PerformanceLevel::EE],
    '79 of 100' => [79, 100, PerformanceLevel::ME],
    '60 of 100' => [60, 100, PerformanceLevel::ME],
    '59 of 100' => [59, 100, PerformanceLevel::AE],
    '40 of 100' => [40, 100, PerformanceLevel::AE],
    '39 of 100' => [39, 100, PerformanceLevel::BE],
    '0 of 10' => [0, 10, PerformanceLevel::BE],
]);

test('a maximum of zero has no level', function () {
    PerformanceLevel::fromShare(0, 0);
})->throws(InvalidArgumentException::class);

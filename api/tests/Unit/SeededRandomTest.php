<?php

use Database\Seeders\Support\SeededRandom;

/* SeededRandom is the reproducibility contract behind SchoolSeeder (#42):
   every figure downstream is only stable if two runs draw the same
   sequence. SeederPlausibilityTest checks aggregate ranges after one seed
   run; this checks the contract itself, method by method, so a draw that
   quietly reads a different source (mt_rand, Faker, the clock) fails here
   rather than as a flaky analytics assertion later. */

/** @return list<mixed> one interleaved pass over every public draw method */
function drawEverything(SeededRandom $random): array
{
    $draws = [];

    for ($i = 0; $i < 50; $i++) {
        $draws[] = $random->int(0, 1000);
        $draws[] = $random->float();
        $draws[] = $random->bool(0.3);
        $draws[] = $random->normal(60, 15);
        $draws[] = $random->pick(['a', 'b', 'c', 'd', 'e']);
        $draws[] = $random->shuffled(range(1, 10));
    }

    return $draws;
}

test('two instances draw identical sequences from every method', function () {
    expect(drawEverything(new SeededRandom))->toBe(drawEverything(new SeededRandom));
});

test('the sequence is not degenerate: draws vary and stay in range', function () {
    $random = new SeededRandom;

    $ints = array_map(fn () => $random->int(0, 1000), range(1, 100));
    expect(count(array_unique($ints)))->toBeGreaterThan(50);
    expect(min($ints))->toBeGreaterThanOrEqual(0)->and(max($ints))->toBeLessThanOrEqual(1000);

    $floats = array_map(fn () => $random->float(), range(1, 100));
    expect(min($floats))->toBeGreaterThanOrEqual(0.0)->and(max($floats))->toBeLessThan(1.0);

    $normals = array_map(fn () => $random->normal(60, 15), range(1, 2000));
    expect(array_sum($normals) / count($normals))->toBeGreaterThan(57)->toBeLessThan(63);

    $shuffled = $random->shuffled(range(1, 10));
    expect($shuffled)->not->toBe(range(1, 10));

    $sorted = $shuffled;
    sort($sorted);
    expect($sorted)->toBe(range(1, 10));
});

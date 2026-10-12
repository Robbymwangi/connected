<?php

use App\Support\Assistant\Grounding;
use App\Support\Assistant\Handles;

/* ADR 0013, decision 3 (grounding) and decision 4 (handles). */

test('an answer is grounded only when every number it cites came from a tool result', function () {
    $results = [['passRatePct' => 62.5, 'meanPct' => 58.33, 'studentsPerLevel' => ['EE' => 4, 'ME' => 11]], ['share' => 0.4]];

    expect(Grounding::holds('62.5% passed, with a mean of 58.3.', $results))->toBeTrue();
    expect(Grounding::holds('About 63% passed; 4 exceed and 11 meet expectations.', $results))->toBeTrue();
    expect(Grounding::holds('40% are below.', $results))->toBeTrue();
    expect(Grounding::holds('62.5% passed, up from 55% last year.', $results))->toBeFalse();
    expect(Grounding::holds('Most students passed.', $results))->toBeFalse();
});

test('handles and term numbers are not figures', function () {
    expect(Grounding::numbersIn('S12 and S3 slipped in Term 2 and T3, to 41.5'))->toBe([41.5]);
});

test('handles are stable within a conversation and go back to names for the screen only', function () {
    $handles = new Handles;

    expect([$handles->for('a'), $handles->for('b'), $handles->for('a')])->toBe(['S1', 'S2', 'S1']);
    expect($handles->studentFor('S2'))->toBe('b');
    expect($handles->studentFor('S9'))->toBeNull();
    foreach (range(3, 12) as $n) {
        $handles->for("x{$n}");
    }

    expect($handles->restore('S1, S12, and S99 need support.', ['a' => 'Amani', 'x12' => 'Baraka']))
        ->toBe('Amani, Baraka, and S99 need support.');
});

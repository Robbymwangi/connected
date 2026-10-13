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

    // For the evaluation's record: which figures failed, or none cited at all.
    expect(Grounding::ungrounded('62.5% passed, up from 55% last year.', $results))->toBe([55.0]);
    expect(Grounding::ungrounded('Most students passed.', $results))->toBe([]);
    expect(Grounding::ungrounded('62.5% passed.', $results))->toBeNull();
});

test('handles and term numbers are not figures', function () {
    expect(Grounding::numbersIn('S12 and S3 slipped in Term 2 and T3, to 41.5'))->toBe([41.5]);
});

test('a name the tools returned is not a figure, and a date they returned grounds its parts', function () {
    $results = [['scope' => ['stream' => '4W'], 'passRatePct' => 80.2], ['assessments' => [
        ['assessment' => 'CAT 1', 'date' => '2026-02-16', 'meanPct' => 58.9],
    ]]];

    expect(Grounding::holds('The pass rate for stream 4W is 80.2%.', $results))->toBeTrue();
    expect(Grounding::holds('Stream 5A passed at 80.2%.', $results))->toBeTrue();
    expect(Grounding::holds('In CAT 1, on 16 Feb 2026, the mean was 58.9%.', $results))->toBeTrue();
    // A number beside a name is still a figure; only the name's own digit is not.
    expect(Grounding::ungrounded('CAT 1 had 12 absentees.', $results))->toBe([12.0]);
    expect(Grounding::ungrounded('That was on 17 Feb.', $results))->toBe([17.0]);
});

test('rounding is allowed to the places written, and nothing the model worked out is', function () {
    $results = [['latestPct' => 51.25, 'earlierMeanPct' => 69.4, 'otherPct' => 50.0]];

    expect(Grounding::holds('S5 fell to 51.3%, or 51.2%, or about 51%.', $results))->toBeTrue();
    expect(Grounding::ungrounded('S5 fell to 51.4%.', $results))->toBe([51.4]);
    // 69.4 - 51.25 = 18.15: a difference is not in any tool result, however it is rounded.
    expect(Grounding::ungrounded('S5 dropped by about 18 points.', $results))->toBe([18.0]);
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

test('names the teacher types become handles before anything is sent', function () {
    $handles = new Handles;
    $roster = ['a' => 'Amina Wanjiru', 'b' => 'John Kamau', 'c' => 'Mary Kamau', 'd' => 'Mark Otieno'];

    expect($handles->conceal('How is amina wanjiru doing, and Amina in CAT 2?', $roster))->toBe('How is S1 doing, and S1 in CAT 2?');
    expect($handles->conceal('Compare John and Otieno; is Kamau improving?', $roster))->toBe('Compare S3 and S2; is Kamau improving?');
    expect($handles->conceal('Who got the highest mark? And Mark?', $roster))->toBe('Who got the highest mark? And S2?');
    expect($handles->conceal('Did Brian pass?', $roster))->toBe('Did Brian pass?');
    expect($handles->all())->toBe(['a' => 'S1', 'd' => 'S2', 'b' => 'S3']);
});

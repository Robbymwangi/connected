<?php

use App\Models\Assessment;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* ADR 0014, decision 4, on the sync path: a new assessment belongs to the term the school is in
   when the create arrives, its sitting date falls in that term's year, and its term and year never
   change afterwards. buildGraph's school is in Term 2, 2026. */

function createEntry(array $g, array $overrides = []): array
{
    return pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, $overrides));
}

test('a create for the open term is accepted', function () {
    $g = buildGraph('School A', '-at-open');
    $entry = createEntry($g);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect(Assessment::find($entry['recordId'])->only(['year', 'term']))->toBe(['year' => 2026, 'term' => 2]);
});

test('a create for any other term is invalid, and says which term the school is in', function (int $year, int $term) {
    $g = buildGraph('School A', "-at-other-{$year}-{$term}");
    $entry = createEntry($g, ['year' => $year, 'term' => $term, 'date' => "{$year}-03-02"]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => "Term {$term}, {$year} is not open; the school is in Term 2, 2026."]);
    expect(Assessment::find($entry['recordId']))->toBeNull();
})->with(['an ended term' => [2026, 1], 'a term not yet opened' => [2026, 3], 'another year' => [2027, 2]]);

test('with no term open, no assessment can be created', function () {
    $g = buildGraph('School A', '-at-none', false);
    $entry = createEntry($g);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'No term is open; the school office opens each term.']);
});

test('a device offline when the next term opened has its assessment refused, and the marks queued behind it with it', function () {
    $g = buildGraph('School A', '-at-offline');
    $entry = createEntry($g);
    $student = enrolledStudent($g, 'Wanjiku Offline');
    $mark = pushEntry('marks', markIdFor($entry['recordId'], $student->id, $g['criterion']->id), 0, [
        'assessmentId' => $entry['recordId'], 'studentId' => $student->id, 'criterionId' => $g['criterion']->id, 'markKind' => 'score', 'score' => 7,
    ]);

    Term::open(makeColleague($g, 'office-at-offline@example.com', admin: true), 2026, 3);
    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry, $mark]));

    expect($results[0]['reason'])->toBe('Term 2, 2026 is not open; the school is in Term 3, 2026.');
    expect($results[1]['status'])->toBe('invalid');
    expect(DB::table('marks')->where('id', $mark['recordId'])->exists())->toBeFalse();
});

test('the sitting date must fall in the term\'s year, on a create and on an edit', function () {
    $g = buildGraph('School A', '-at-date');
    $create = createEntry($g, ['date' => '2025-08-18']);
    $edit = pushEntry('assessments', $g['assessment']->id, 1, ['date' => '2062-02-01']);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$create, $edit]));

    expect($results[0]['reason'])->toBe('The sitting date must be in 2026, the year of the assessment\'s term.');
    expect($results[1]['reason'])->toBe('The sitting date must be in 2026, the year of the assessment\'s term.');
    expect($g['assessment']->fresh()->date->toDateString())->toBe('2026-02-01');
});

test('an assessment\'s term and year are fixed once created, even before it has marks', function (string $field, int $value) {
    $g = buildGraph('School A', "-at-fixed-{$field}");
    $g['mark']->delete();
    $entry = pushEntry('assessments', $g['assessment']->id, 1, [$field => $value]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => "An assessment's term and year are fixed when it is created."]);
    expect($g['assessment']->fresh()->only(['year', 'term']))->toBe(['year' => 2026, 'term' => 1]);
})->with(['term moved to the open term' => ['term', 2], 'year' => ['year', 2027]]);

test('sending the term and year an assessment already has is not a change, and is accepted', function () {
    $g = buildGraph('School A', '-at-same');
    $entry = pushEntry('assessments', $g['assessment']->id, 1, ['term' => 1, 'year' => 2026, 'name' => 'CAT 1 (renamed)']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect($g['assessment']->fresh()->name)->toBe('CAT 1 (renamed)');
});

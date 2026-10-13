<?php

use App\Models\Criterion;
use App\Models\Mark;
use App\Models\Result;
use App\Models\Student;
use App\Models\User;
use App\Support\PerformanceLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/* 4.3, results at finalize: both finalize paths write a results row, in the finalize's own
   transaction, for every student with at least one scored mark; unlock soft deletes them and the
   next finalize writes new rows. docs/spec/workflow.md (Finalize, Unlock), docs/spec/data-model.md. */

function resultCell(array $graph, Student $student, Criterion $criterion, string $kind, ?int $score = null): Mark
{
    return Mark::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $student->id,
        'criterion_id' => $criterion->id,
        'mark_kind' => $kind,
        'score' => $score,
        'last_edited_by' => $graph['teacher']->id,
    ]);
}

/* SyncPushFinalizeTest's finalizeEntry under another name, so this file runs on its own. */
function resultsFinalizeEntry(array $graph, User $by, int $base = 1): array
{
    return pushEntry('assessments', $graph['assessment']->id, $base, ['status' => 'finalized', 'finalizedBy' => $by->id]);
}

/** @return array{total: int, max: int, level: string}|null */
function liveResultFor(array $graph, Student $student): ?array
{
    $row = DB::table('results')
        ->where('assessment_id', $graph['assessment']->id)
        ->where('student_id', $student->id)
        ->whereNull('deleted_at')
        ->first(['total', 'max', 'level']);

    return $row === null ? null : (array) $row;
}

test('the online finalize writes a versioned, logged result for a fully scored student', function () {
    $g = buildGraph();

    $g['assessment']->finalize($g['teacher']);

    $result = Result::query()->where('assessment_id', $g['assessment']->id)->sole();
    expect($result->only(['institution_id', 'student_id', 'total', 'max', 'level', 'version']))->toBe([
        'institution_id' => $g['institution']->id,
        'student_id' => $g['student']->id,
        'total' => 8,
        'max' => 10,
        'level' => 'EE',
        'version' => 1,
    ]);
    expect(DB::table('sync_changes')->where('record_id', $result->id)->where('version', 1)->exists())->toBeTrue();
});

test('max leaves out absent criteria, counts empty and unentered ones, and ignores deleted criteria', function () {
    $g = buildGraph();
    $reasoning = newCriterion($g, 'Reasoning', 10);
    $retired = newCriterion($g, 'Retired', 50);

    // The graph's student: 8 on Accuracy, absent on Reasoning, so 8 out of 10.
    resultCell($g, $g['student'], $reasoning, 'absent');
    resultCell($g, $g['student'], $retired, 'score', 50);

    $gaps = enrolledStudent($g, 'Gaps');
    resultCell($g, $gaps, $g['criterion'], 'score', 5);
    resultCell($g, $gaps, $reasoning, 'empty');

    $unentered = enrolledStudent($g, 'Unentered');
    resultCell($g, $unentered, $reasoning, 'score', 9);

    $absent = enrolledStudent($g, 'Absent throughout');
    resultCell($g, $absent, $g['criterion'], 'absent');
    resultCell($g, $absent, $reasoning, 'absent');

    $empty = enrolledStudent($g, 'Nothing scored');
    resultCell($g, $empty, $g['criterion'], 'empty');

    $retired->delete();

    $g['assessment']->finalize($g['teacher']);

    expect(liveResultFor($g, $g['student']))->toBe(['total' => 8, 'max' => 10, 'level' => 'EE']);
    expect(liveResultFor($g, $gaps))->toBe(['total' => 5, 'max' => 20, 'level' => 'BE']);
    expect(liveResultFor($g, $unentered))->toBe(['total' => 9, 'max' => 20, 'level' => 'AE']);
    expect(liveResultFor($g, $absent))->toBeNull();
    expect(liveResultFor($g, $empty))->toBeNull();
});

test('unlock soft deletes the results and the next finalize writes new rows from the marks as they stand', function () {
    $g = buildGraph();
    $g['teacher']->update(['directs_studies' => true]);
    $g['assessment']->finalize($g['teacher']);
    $first = Result::query()->where('assessment_id', $g['assessment']->id)->sole();

    $g['assessment']->unlock($g['teacher']);

    $this->assertSoftDeleted($first);
    expect(DB::table('sync_changes')->where('record_id', $first->id)->where('version', 2)->exists())->toBeTrue();

    $g['mark']->update(['score' => 5]);
    $g['assessment']->finalize($g['teacher']);

    $second = Result::query()->where('assessment_id', $g['assessment']->id)->sole();
    expect($second->id)->not->toBe($first->id);
    expect($second->only(['total', 'max', 'level']))->toBe(['total' => 5, 'max' => 10, 'level' => 'AE']);
    expect(Result::withTrashed()->where('assessment_id', $g['assessment']->id)->count())->toBe(2);
});

test('a finalize that fails writes no results', function () {
    $g = buildGraph();
    $g['assessment']->update(['status' => 'finalized']);

    expect(fn () => $g['assessment']->finalize($g['teacher']))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('results', 0);
});

test('a pushed finalize writes the results', function () {
    $g = buildGraph('School A', '-res-push');
    $entry = resultsFinalizeEntry($g, $g['teacher']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect(liveResultFor($g, $g['student']))->toBe(['total' => 8, 'max' => 10, 'level' => 'EE']);
});

test('a stale finalize that merges past a rename still writes the results', function () {
    $g = buildGraph('School A', '-res-merge');
    $g['assessment']->update(['name' => 'Renamed']);
    $entry = resultsFinalizeEntry($g, $g['teacher'], base: 1);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('merged');
    expect(liveResultFor($g, $g['student']))->toBe(['total' => 8, 'max' => 10, 'level' => 'EE']);
});

test('a second device finalizing an already finalized assessment writes no second result', function () {
    $g = buildGraph('School A', '-res-twice');
    $assigned = makeColleague($g, 'second-res@example.com', assigned: true);

    postedResults(pushEntries($this, tokenFor($g['teacher']), [resultsFinalizeEntry($g, $g['teacher'])]));
    app('auth')->forgetGuards();
    $result = postedResults(pushEntries($this, tokenFor($assigned), [resultsFinalizeEntry($g, $assigned)]))[0];

    expect($result['status'])->toBe('accepted');
    expect(Result::withTrashed()->where('assessment_id', $g['assessment']->id)->count())->toBe(1);
});

test('the SQL level agrees with the PHP level at and either side of every threshold', function () {
    $pairs = [[48, 60], [47, 60], [8, 10], [79, 100], [60, 100], [59, 100], [40, 100], [39, 100], [0, 10], [10, 10]];

    foreach ($pairs as [$total, $max]) {
        $share = "{$total}::double precision / {$max}";
        $row = DB::selectOne('select '.PerformanceLevel::sqlCase($share).' as level, '.PerformanceLevel::sqlRank($share).' as level_rank');
        $level = PerformanceLevel::fromShare($total, $max);

        expect([$row->level, (int) $row->level_rank])->toBe([$level->value, $level->rank()], "{$total} out of {$max}");
    }
});

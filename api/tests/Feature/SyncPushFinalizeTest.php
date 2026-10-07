<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* 3.2c, C: finalizing an assessment over POST /sync. It is its own entry, never mixed with a field
   edit, and it runs the same checks as the online finalize (authorization, scheduled, no open mark
   conflict) through Assessment::prepareFinalize. `finalizedBy` must be the token's user and
   `finalizedAt` is the device's informational claim, ignored: the server stamps its own clock.
   Unlock is not a mutation. docs/spec/sync-protocol.md, docs/spec/workflow.md. */

function finalizeEntry(array $graph, User $by, int $base = 1, array $extra = []): array
{
    return pushEntry('assessments', $graph['assessment']->id, $base, array_merge([
        'status' => 'finalized',
        'finalizedBy' => $by->id,
        'finalizedAt' => '2026-10-07T08:00:00Z',
    ], $extra));
}

test('the creator finalizes: accepted, the version moves, finalized_by is the user, the clock is the server\'s, and the change is logged', function () {
    $g = buildGraph('School A', '-fin-creator');
    $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
    $entry = finalizeEntry($g, $g['teacher']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 2]);
    $row = DB::table('assessments')->where('id', $g['assessment']->id)->first();
    expect($row)->toMatchArray(['status' => 'finalized', 'finalized_by' => $g['teacher']->id, 'version' => 2]);
    expect($row->finalized_at)->toStartWith('2026-10-07 10:00:00');
    $logged = DB::table('sync_changes')->where('record_id', $g['assessment']->id)->where('version', 2)->first();
    expect(array_keys(json_decode($logged->fields, true)))->toEqualCanonicalizing(['status', 'finalized_at', 'finalized_by']);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('change_seq'))->toBe($logged->seq);
});

test('an assigned teacher finalizes an assessment they did not create', function () {
    $g = buildGraph('School A', '-fin-assigned');
    $assigned = makeColleague($g, 'assigned-fin@example.com', assigned: true);
    $entry = finalizeEntry($g, $assigned);

    $result = postedResults(pushEntries($this, tokenFor($assigned), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->first())->toMatchArray(['created_by' => $g['teacher']->id, 'finalized_by' => $assigned->id]);
});

test('a teacher who neither created nor teaches it is forbidden, admin or not, at the current base and at a stale one', function (bool $admin) {
    $g = buildGraph('School A', '-fin-bystander');
    $bystander = makeColleague($g, 'bystander-fin@example.com', admin: $admin);
    $current = finalizeEntry($g, $bystander, base: 1);
    $stale = finalizeEntry($g, $bystander, base: 0);

    $results = postedResults(pushEntries($this, tokenFor($bystander), [$current, $stale]));

    expect(array_column($results, 'status'))->toBe(['forbidden', 'forbidden']);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('status'))->toBe('scheduled');
})->with(['a teacher' => false, 'an admin' => true]);

test('finalizedBy naming another user, or missing, is invalid', function () {
    $g = buildGraph('School A', '-fin-by');
    $colleague = makeColleague($g, 'by-fin@example.com');
    $other = finalizeEntry($g, $colleague);
    $missing = finalizeEntry($g, $g['teacher']);
    unset($missing['fields']['finalizedBy']);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$other, $missing]));

    expect($results[0])->toBe(['id' => $other['id'], 'status' => 'invalid', 'reason' => 'finalizedBy must be the signed-in user']);
    expect($results[1]['status'])->toBe('invalid');
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('status'))->toBe('scheduled');
});

test('a finalizedBy that is not a string is invalid, never compared loosely', function (mixed $by) {
    $g = buildGraph('School A', '-fin-by-type');
    $entry = finalizeEntry($g, $g['teacher'], extra: ['finalizedBy' => $by]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'finalizedBy must be the signed-in user']);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('status'))->toBe('scheduled');
})->with(['an integer' => [5], 'an array' => [['x']], 'a boolean' => [true], 'null' => [null]]);

test('a status other than finalized is invalid, since unlocking is an administrator action', function (mixed $status) {
    $g = buildGraph('School A', '-fin-status');
    $entry = finalizeEntry($g, $g['teacher'], extra: ['status' => $status]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'status changes only by finalize; unlocking is an administrator action']);
})->with(['scheduled' => ['scheduled'], 'reports-generated' => ['reports-generated'], 'junk' => ['x'], 'null' => [null]]);

test('finalize is sent on its own: mixed with a field, or missing a half, it is invalid', function (array $shape) {
    $g = buildGraph('School A', '-fin-shape');
    $fields = collect($shape)->map(fn ($value) => $value === 'USER' ? $g['teacher']->id : $value)->all();
    $entry = pushEntry('assessments', $g['assessment']->id, 1, $fields);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'finalize is sent on its own: status, finalizedBy, finalizedAt']);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('status'))->toBe('scheduled');
})->with([
    'mixed with a name change' => [['status' => 'finalized', 'finalizedBy' => 'USER', 'name' => 'X']],
    'without finalizedBy' => [['status' => 'finalized']],
    'finalizedBy without status' => [['finalizedBy' => 'USER']],
    'finalizedAt alone' => [['finalizedAt' => '2026-10-07T08:00:00Z']],
]);

test('a create carrying status is invalid: an assessment is created scheduled', function () {
    $g = buildGraph('School A', '-fin-create');
    $entry = pushEntry('assessments', (string) Str::uuid7(), 0, assessmentFields($g, ['status' => 'finalized']));

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'an assessment is created scheduled; finalize is its own entry']);
    expect(DB::table('assessments')->where('id', $entry['recordId'])->exists())->toBeFalse();
});

test('an open conflict on one of its marks makes finalize invalid, with finalize\'s own reason', function () {
    $g = buildGraph('School A', '-fin-open-conflict');
    $b = makeColleague($g, 'b-fin-open@example.com');
    postedResults(pushEntries($this, tokenFor($g['teacher']), [pushEntry('marks', $g['mark']->id, 1, ['score' => 6])]));
    app('auth')->forgetGuards();
    $conflict = postedResults(pushEntries($this, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => 9])]))[0];
    expect($conflict['status'])->toBe('conflict');
    app('auth')->forgetGuards();
    $entry = finalizeEntry($g, $g['teacher']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'An assessment with open mark conflicts cannot be finalized.']);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('status'))->toBe('scheduled');
});

test('an auto conflict does not block finalize', function () {
    $g = buildGraph('School A', '-fin-auto');
    $b = makeColleague($g, 'b-fin-auto@example.com');
    postedResults(pushEntries($this, tokenFor($g['teacher']), [pushEntry('marks', $g['mark']->id, 1, ['score' => 6])]));
    app('auth')->forgetGuards();
    postedResults(pushEntries($this, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => 6])]));
    app('auth')->forgetGuards();
    $entry = finalizeEntry($g, $g['teacher']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect(DB::table('conflicts')->where('mark_id', $g['mark']->id)->count())->toBe(1);
});

test('an incomplete assessment finalizes: marks are not required for every student', function () {
    $g = buildGraph('School A', '-fin-incomplete');
    enrolledStudent($g, 'No marks yet');
    $entry = finalizeEntry($g, $g['teacher']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
});

test('two devices finalize from the same base: the second is accepted at the unchanged version and the credit stays with the first', function () {
    $g = buildGraph('School A', '-fin-two');
    $assigned = makeColleague($g, 'second-fin@example.com', assigned: true);
    $first = finalizeEntry($g, $g['teacher']);
    $second = finalizeEntry($g, $assigned, extra: ['finalizedAt' => '2000-01-01T00:00:00Z']);

    postedResults(pushEntries($this, tokenFor($g['teacher']), [$first]));
    app('auth')->forgetGuards();
    $result = postedResults(pushEntries($this, tokenFor($assigned), [$second]))[0];

    expect($result)->toBe(['id' => $second['id'], 'status' => 'accepted', 'version' => 2]);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->first())->toMatchArray(['finalized_by' => $g['teacher']->id, 'version' => 2]);
    expect(DB::table('sync_changes')->where('record_id', $g['assessment']->id)->where('version', 2)->count())->toBe(1);
});

test('finalize at the current version of an already finalized or reports-generated assessment is accepted at the unchanged version', function (string $status) {
    $g = buildGraph('School A', '-fin-already-'.$status);
    $g['assessment']->update(['status' => $status]);
    $entry = finalizeEntry($g, $g['teacher'], base: 2);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 2]);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('status'))->toBe($status);
})->with(['finalized', 'reports-generated']);

test('a finalize queued before an administrator unlocked it is a conflict carrying the current row, and writes nothing', function () {
    $g = buildGraph('School A', '-fin-unlocked');
    $g['teacher']->update(['is_admin' => true]);
    $g['assessment']->finalize($g['teacher']);
    $g['assessment']->unlock($g['teacher']);
    $entry = finalizeEntry($g, $g['teacher'], base: 1);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('conflict');
    expect($result['current']['fields']['status'])->toBe('scheduled');
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('status'))->toBe('scheduled');
});

test('a stale finalize whose base only missed a rename merges and finalizes', function () {
    $g = buildGraph('School A', '-fin-merge');
    $g['assessment']->update(['name' => 'Renamed']);
    $entry = finalizeEntry($g, $g['teacher'], base: 1);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'merged', 'version' => 3]);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->first())->toMatchArray(['status' => 'finalized', 'name' => 'Renamed']);
});

test('a mark edit after the finalize in the same batch is invalid', function () {
    $g = buildGraph('School A', '-fin-then-mark');
    $finalize = finalizeEntry($g, $g['teacher']);
    $edit = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$finalize, $edit]));

    expect($results[0]['status'])->toBe('accepted');
    expect($results[1]['status'])->toBe('invalid');
    expect($results[1]['reason'])->toBe('Marks cannot be changed while the assessment is finalized. Unlock it first.');
});

test('a resend of a finalize is replayed', function () {
    $g = buildGraph('School A', '-fin-replay');
    $token = tokenFor($g['teacher']);
    $entry = finalizeEntry($g, $g['teacher']);

    $first = postedResults(pushEntries($this, $token, [$entry]))[0];
    app('auth')->forgetGuards();
    $second = postedResults(pushEntries($this, $token, [$entry]))[0];

    expect($second)->toBe($first + ['replayed' => true]);
    expect(DB::table('sync_changes')->where('record_id', $g['assessment']->id)->where('version', 2)->count())->toBe(1);
});

test('a finalizedAt of any shape is ignored, never a 500', function (mixed $claim) {
    $g = buildGraph('School A', '-fin-claim');
    $entry = finalizeEntry($g, $g['teacher'], extra: ['finalizedAt' => $claim]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('finalized_at'))->not->toBeNull();
})->with([
    'a nested array' => [['a' => ['b' => 1]]],
    'a NUL in the middle' => ["2026-10-07\0T08:00"],
    'five hundred characters' => [str_repeat('x', 500)],
    'a number' => [12345],
]);

<?php

use App\Models\Assessment;
use App\Models\SyncMutation;
use App\Models\User;
use App\Support\CurrentInstitution;
use App\Sync\Push\PushEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* 3.2a, C3: POST /sync, the batch loop and the decisions that do not depend on a
   table's own rules: the envelope, replay (rule 1), forbidden (rule 6), the
   recording of rejections, create (rule 3), and a stale or ahead base. Assessment
   create is the table that exercises them. docs/spec/sync-protocol.md. */

test('push needs a token and the sync ability', function () {
    $g = buildGraph('School A', '-push-auth');

    $this->postJson('/api/sync', ['entries' => []])->assertUnauthorized();

    $withoutAbility = $g['teacher']->createToken('device', [])->plainTextToken;
    pushEntries($this, $withoutAbility, [])->assertForbidden();

    app('auth')->forgetGuards();
    pushEntries($this, tokenFor($g['teacher']), [])->assertOk();
});

test('an empty batch returns an empty result list', function () {
    $g = buildGraph('School A', '-push-empty');

    expect(postedResults(pushEntries($this, tokenFor($g['teacher']), [])))->toBe([]);
});

test('a malformed batch is a 422 and nothing is recorded', function (mixed $entries) {
    $g = buildGraph('School A', '-push-422');
    $before = SyncMutation::count();

    $this->withToken(tokenFor($g['teacher']))->postJson('/api/sync', ['entries' => $entries])->assertUnprocessable();

    expect(SyncMutation::count())->toBe($before);
})->with([
    'not a list' => [['a' => 1]],
    'a scalar element' => [['not an object']],
    'more than 100 entries' => [fn () => array_fill(0, 101, ['id' => 'x'])],
]);

test('a missing entries key is a 422', function () {
    $g = buildGraph('School A', '-push-422b');

    $this->withToken(tokenFor($g['teacher']))->postJson('/api/sync', [])->assertUnprocessable();
});

test('a valid assessment create is accepted at version 1 and logged and recorded', function () {
    $g = buildGraph('School A', '-push-create');
    $recordId = Str::uuid7()->toString();
    $entry = pushEntry('assessments', $recordId, 0, assessmentFields($g), at: '2026-08-18T09:00:00+03:00');

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]));

    expect($results)->toBe([['id' => $entry['id'], 'status' => 'accepted', 'version' => 1]]);

    $assessment = DB::table('assessments')->where('id', $recordId)->first();
    expect($assessment->created_by)->toBe($g['teacher']->id);
    expect($assessment->status)->toBe('scheduled');
    expect($assessment->institution_id)->toBe($g['institution']->id);
    expect($assessment->version)->toBe(1);

    expect(DB::table('sync_changes')->where('table', 'assessments')->where('record_id', $recordId)->count())->toBe(1);

    $mutation = DB::table('sync_mutations')->where('id', $entry['id'])->first();
    expect($mutation->status)->toBe('accepted');
    expect($mutation->version)->toBe(1);
    expect($mutation->user_id)->toBe($g['teacher']->id);
    expect($mutation->table)->toBe('assessments');
    expect($mutation->record_id)->toBe($recordId);
    expect($mutation->at)->toBe('2026-08-18T09:00:00+03:00');
    expect($mutation->payload_hash)->toHaveLength(64);
});

test('an accepted create records the seq of the change it wrote', function () {
    $g = buildGraph('School A', '-push-seq');
    $recordId = Str::uuid7()->toString();
    $entry = pushEntry('assessments', $recordId, 0, assessmentFields($g));

    postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]));

    $seq = DB::table('sync_changes')->where('table', 'assessments')->where('record_id', $recordId)->where('version', 1)->value('seq');
    expect($seq)->not->toBeNull();
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('change_seq'))->toBe($seq);
});

test('a rejection or a conflict records no change seq, since it wrote nothing', function () {
    $g = buildGraph('School A', '-push-noseq');
    $rejected = pushEntry('widgets', Str::uuid7()->toString(), 0, ['name' => 'x']);
    $stale = pushEntry('assessments', $g['assessment']->id, 0, ['name' => 'Another name']);

    postedResults(pushEntries($this, tokenFor($g['teacher']), [$rejected, $stale]));

    expect(DB::table('sync_mutations')->whereIn('id', [$rejected['id'], $stale['id']])->whereNotNull('change_seq')->count())->toBe(0);
});

test('an unknown table is invalid and recorded', function () {
    $g = buildGraph('School A', '-push-unknown');
    $entry = pushEntry('widgets', Str::uuid7()->toString(), 0, ['name' => 'x']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'unknown table: widgets']);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('status'))->toBe('invalid');
});

test('a pull-only table is invalid and recorded', function () {
    $g = buildGraph('School A', '-push-pullonly');
    $entry = pushEntry('students', $g['student']->id, 1, ['name' => 'Renamed']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBe('table is pull-only: students');
    expect($g['student']->fresh()->name)->toBe('S. Student');
});

test('a server-owned field is invalid', function (string $field) {
    $g = buildGraph('School A', '-push-owned');
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, [$field => 'x']));

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => "server-owned field: {$field}"]);
})->with(['createdBy', 'version', 'institutionId', 'lastEditedBy', 'receivedAt']);

test('a snake_case field name is invalid, never converted', function () {
    $g = buildGraph('School A', '-push-snake');
    $fields = assessmentFields($g);
    $fields['class_id'] = $fields['classId'];
    unset($fields['classId']);
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 0, $fields);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBe('unknown field: class_id');
});

test('status and deletedAt on an assessment are invalid', function (string $field) {
    $g = buildGraph('School A', '-push-refused');
    $entry = pushEntry('assessments', $g['assessment']->id, 1, [$field => $field === 'deletedAt' ? '2026-01-01T00:00:00Z' : 'finalized']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect($g['assessment']->fresh()->status)->toBe('scheduled');
})->with(['status', 'deletedAt']);

test('a bad baseVersion is invalid', function (mixed $base) {
    $g = buildGraph('School A', '-push-base');
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g));
    $entry['baseVersion'] = $base;

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBe('baseVersion must be a non-negative integer');
})->with([-1, 1.5, '1', null, true]);

test('empty or list-shaped fields are invalid', function (array $fields) {
    $g = buildGraph('School A', '-push-fields');
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 0, $fields);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBe('fields must be a non-empty object');
})->with(['empty' => [[]], 'a list' => [['a', 'b']]]);

test('an entry whose id or recordId is not a UUID is invalid and not recorded', function () {
    $g = buildGraph('School A', '-push-uuid');
    $before = SyncMutation::count();
    $badId = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g), 'not-a-uuid');
    $badRecord = pushEntry('assessments', '1 or 1=1', 0, assessmentFields($g));

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$badId, $badRecord]));

    expect($results[0]['status'])->toBe('invalid');
    expect($results[1]['status'])->toBe('invalid');
    expect($results[1]['id'])->toBe($badRecord['id']);
    expect(SyncMutation::count())->toBe($before);
});

test('an assessment value Postgres would refuse is invalid, never a 500 that stalls the outbox', function (string $field, mixed $value) {
    $g = buildGraph('School A', '-push-poison');
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, [$field => $value]));

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('status'))->toBe('invalid');
})->with([
    'a NUL byte in the name' => ['name', "CAT\0 2"],
    'a name over 255 characters' => ['name', str_repeat('a', 256)],
    'a blank name' => ['name', '   '],
    'a non-UUID class' => ['classId', '1 or 1=1'],
    'a term of 4' => ['term', 4],
    'a fractional year' => ['year', 2026.5],
    'a year over 9999' => ['year', 10000],
    'a date that is not real' => ['date', '2026-02-30'],
    'year zero in the date' => ['date', '0000-01-01'],
    'a date in the wrong form' => ['date', '18/08/2026'],
]);

test('a table name Postgres would refuse is invalid and not recorded, never a 500', function (string $table) {
    $g = buildGraph('School A', '-push-poison-table');
    $before = SyncMutation::count();
    $entry = pushEntry($table, Str::uuid7()->toString(), 0, ['name' => 'x']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect(SyncMutation::count())->toBe($before);
})->with([
    'a NUL byte' => ["wid\0gets"],
    'upper case' => ['Widgets'],
    'a space' => ['wid gets'],
    'a quote' => ["widgets'"],
    'over 64 characters' => [str_repeat('a', 65)],
]);

test('an at claim Postgres would refuse is dropped, and the entry is decided normally', function (string $at) {
    $g = buildGraph('School A', '-push-poison-at');
    $recordId = Str::uuid7()->toString();
    $entry = pushEntry('assessments', $recordId, 0, assessmentFields($g), at: $at);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('at'))->toBeNull();
})->with([
    'a NUL byte in the middle' => ["2026-08-18\0T09:00"],
    'over 64 bytes' => [str_repeat('x', 65)],
]);

test('a multibyte name is measured in characters, as the column is', function () {
    $g = buildGraph('School A', '-push-multibyte');
    $name = str_repeat('é', 255);
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, ['name' => $name]));

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect(DB::table('assessments')->where('id', $entry['recordId'])->value('name'))->toBe($name);
});

test('a nonzero baseVersion for an id unknown in this institution is forbidden and recorded', function () {
    $g = buildGraph('School A', '-push-unknownid');
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 3, ['name' => 'Renamed']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'forbidden']);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('status'))->toBe('forbidden');
});

test('a create whose id exists in another institution is forbidden, recorded after the rollback, and the other row untouched', function () {
    $a = buildGraph('School A', '-push-xinst-a');
    $b = buildGraph('School B', '-push-xinst-b');
    $entry = pushEntry('assessments', $b['assessment']->id, 0, assessmentFields($a));

    $result = postedResults(pushEntries($this, tokenFor($a['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'forbidden']);

    $other = DB::table('assessments')->where('id', $b['assessment']->id)->first();
    expect($other->institution_id)->toBe($b['institution']->id);
    expect($other->name)->not->toBe('CAT 2');
    expect($other->version)->toBe(1);

    $mutation = DB::table('sync_mutations')->where('id', $entry['id'])->first();
    expect($mutation->status)->toBe('forbidden');
    expect($mutation->institution_id)->toBe($a['institution']->id);
    expect(DB::table('sync_changes')->where('record_id', $b['assessment']->id)->where('institution_id', $a['institution']->id)->count())->toBe(0);
});

test('a create at baseVersion 0 against an existing id is a conflict carrying the current row', function () {
    $g = buildGraph('School A', '-push-conflict');
    $entry = pushEntry('assessments', $g['assessment']->id, 0, ['name' => 'Another name']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('conflict');
    expect($result)->not->toHaveKey('conflictId');
    expect($result['current']['table'])->toBe('assessments');
    expect($result['current']['recordId'])->toBe($g['assessment']->id);
    expect($result['current']['version'])->toBe(1);
    expect($result['current']['fields']['name'])->toBe($g['assessment']->name);
    expect($g['assessment']->fresh()->name)->toBe($g['assessment']->name);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->first())->toMatchArray(['status' => 'conflict', 'version' => 1]);
});

test('a baseVersion ahead of the server is invalid', function () {
    $g = buildGraph('School A', '-push-ahead');
    $entry = pushEntry('assessments', $g['assessment']->id, 9, ['name' => 'Another name']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'baseVersion is ahead of the server']);
});

test('a resend of an accepted entry is replayed with nothing written twice, even when only at differs', function () {
    $g = buildGraph('School A', '-push-replay');
    $token = tokenFor($g['teacher']);
    $recordId = Str::uuid7()->toString();
    $entry = pushEntry('assessments', $recordId, 0, assessmentFields($g), at: '2026-08-18T09:00:00Z');

    postedResults(pushEntries($this, $token, [$entry]));
    $resend = $entry;
    $resend['at'] = '2026-08-19T10:00:00Z';
    $result = postedResults(pushEntries($this, $token, [$resend]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 1, 'replayed' => true]);
    expect(DB::table('sync_changes')->where('record_id', $recordId)->count())->toBe(1);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->count())->toBe(1);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('at'))->toBe('2026-08-18T09:00:00Z');
});

test('a resend of a rejected entry replays the stored reason', function () {
    $g = buildGraph('School A', '-push-replay-rej');
    $token = tokenFor($g['teacher']);
    $entry = pushEntry('widgets', Str::uuid7()->toString(), 0, ['name' => 'x']);

    $first = postedResults(pushEntries($this, $token, [$entry]))[0];
    $second = postedResults(pushEntries($this, $token, [$entry]))[0];

    expect($second)->toBe($first + ['replayed' => true]);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->count())->toBe(1);
});

test('a known id with a different payload is invalid and the stored outcome is unchanged', function () {
    $g = buildGraph('School A', '-push-replay-diff');
    $token = tokenFor($g['teacher']);
    $recordId = Str::uuid7()->toString();
    $entry = pushEntry('assessments', $recordId, 0, assessmentFields($g));
    postedResults(pushEntries($this, $token, [$entry]));

    $changed = $entry;
    $changed['fields']['name'] = 'A later edit coalesced in';
    $result = postedResults(pushEntries($this, $token, [$changed]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'mutation id was already used with a different payload']);
    expect(DB::table('assessments')->where('id', $recordId)->value('name'))->toBe('CAT 2');
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('status'))->toBe('accepted');
});

test('a known id from another user in the same school is invalid, never replayed', function () {
    $g = buildGraph('School A', '-push-replay-user');
    $colleague = User::create([
        'institution_id' => $g['institution']->id,
        'name' => 'Colleague',
        'email' => 'colleague-push@example.com',
        'password' => 'a-hashed-password',
    ]);
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g));
    postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]));

    app('auth')->forgetGuards();
    $result = postedResults(pushEntries($this, tokenFor($colleague), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'mutation id was already used']);
});

test('a known id from another institution is invalid, never replayed', function () {
    $a = buildGraph('School A', '-push-replay-inst-a');
    $b = buildGraph('School B', '-push-replay-inst-b');
    $entry = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($a));
    postedResults(pushEntries($this, tokenFor($a['teacher']), [$entry]));

    app('auth')->forgetGuards();
    app(CurrentInstitution::class)->reset();
    $result = postedResults(pushEntries($this, tokenFor($b['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'mutation id was already used']);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('institution_id'))->toBe($a['institution']->id);
});

test('the payload hash ignores key order at every level and at, but not baseVersion', function () {
    $raw = ['id' => (string) Str::uuid7(), 'table' => 'assessments', 'recordId' => (string) Str::uuid7(), 'baseVersion' => 1,
        'fields' => ['name' => 'x', 'nested' => ['b' => 1, 'a' => 2]], 'at' => 'one'];

    $reordered = $raw;
    $reordered['fields'] = ['nested' => ['a' => 2, 'b' => 1], 'name' => 'x'];
    $reordered['at'] = 'two';
    $otherId = $raw;
    $otherId['id'] = (string) Str::uuid7();
    $otherBase = $raw;
    $otherBase['baseVersion'] = 2;
    $otherValue = $raw;
    $otherValue['fields']['name'] = 'y';

    $hash = PushEntry::from($raw)->payloadHash;

    expect(PushEntry::from($reordered)->payloadHash)->toBe($hash);
    expect(PushEntry::from($otherId)->payloadHash)->toBe($hash);
    expect(PushEntry::from($otherBase)->payloadHash)->not->toBe($hash);
    expect(PushEntry::from($otherValue)->payloadHash)->not->toBe($hash);
});

test('an invalid entry does not block the rest, and results keep request order', function () {
    $g = buildGraph('School A', '-push-order');
    $first = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, ['name' => 'First']));
    $bad = pushEntry('widgets', Str::uuid7()->toString(), 0, ['name' => 'x']);
    $last = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, ['name' => 'Last']));

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$first, $bad, $last]));

    expect(array_column($results, 'id'))->toBe([$first['id'], $bad['id'], $last['id']]);
    expect(array_column($results, 'status'))->toBe(['accepted', 'invalid', 'accepted']);
    expect(DB::table('assessments')->whereIn('name', ['First', 'Last'])->count())->toBe(2);
});

test('an unexpected error aborts the batch with a 500, earlier entries stay committed, and a resend decides the rest afresh', function () {
    $g = buildGraph('School A', '-push-500');
    $token = tokenFor($g['teacher']);
    $first = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, ['name' => 'Before']));
    $failing = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, ['name' => 'boom']));
    $never = pushEntry('assessments', Str::uuid7()->toString(), 0, assessmentFields($g, ['name' => 'After']));

    $fail = true;
    Assessment::creating(function (Assessment $assessment) use (&$fail) {
        if ($fail && $assessment->name === 'boom') {
            throw new RuntimeException('boom');
        }
    });

    pushEntries($this, $token, [$first, $failing, $never])->assertStatus(500);

    expect(DB::table('assessments')->where('name', 'Before')->exists())->toBeTrue();
    expect(DB::table('sync_mutations')->where('id', $first['id'])->value('status'))->toBe('accepted');
    expect(DB::table('sync_mutations')->where('id', $failing['id'])->exists())->toBeFalse();
    expect(DB::table('assessments')->where('name', 'After')->exists())->toBeFalse();

    $fail = false;
    app('auth')->forgetGuards();
    $results = postedResults(pushEntries($this, $token, [$first, $failing, $never]));

    expect(array_column($results, 'status'))->toBe(['accepted', 'accepted', 'accepted']);
    expect($results[0]['replayed'] ?? false)->toBeTrue();
    expect($results[1])->not->toHaveKey('replayed');
    expect(DB::table('assessments')->whereIn('name', ['Before', 'boom', 'After'])->count())->toBe(3);
});

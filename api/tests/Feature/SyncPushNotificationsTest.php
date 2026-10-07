<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* 3.2c, B: a user marks their own notification read or unread from a device. Notifications are
   written by the server and pulled; `unread` is the one field a device pushes back
   (docs/spec/sync-protocol.md, Scope). It is a personal feed, so another user's notification
   behaves like an unknown id, and nothing about it is revealed either way. */

function readEntry(array $notification, bool $unread, int $base = 1): array
{
    return pushEntry('notifications', $notification['id'], $base, ['unread' => $unread]);
}

test('the recipient marks a notification read at the current version: accepted, the change logged and its seq recorded', function () {
    $g = buildGraph('School A', '-nt-read');
    $notification = notificationFor($g['teacher']);
    $entry = readEntry($notification->toArray(), false);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 2]);
    expect(DB::table('notifications')->where('id', $notification->id)->first())->toMatchArray(['unread' => false, 'version' => 2]);
    $logged = DB::table('sync_changes')->where('record_id', $notification->id)->where('version', 2)->first();
    expect(json_decode($logged->fields, true))->toBe(['unread' => false]);
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('change_seq'))->toBe($logged->seq);
});

test('marking an already-read notification read is accepted at the unchanged version and logs nothing', function () {
    $g = buildGraph('School A', '-nt-noop');
    $notification = notificationFor($g['teacher'], ['unread' => false]);
    $logRows = DB::table('sync_changes')->where('record_id', $notification->id)->count();
    $entry = readEntry($notification->toArray(), false);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 1]);
    expect(DB::table('sync_changes')->where('record_id', $notification->id)->count())->toBe($logRows);
});

test('two devices of one user mark it read from the same base: the second is accepted at the unchanged version, with no conflict record', function () {
    $g = buildGraph('School A', '-nt-two-devices');
    $notification = notificationFor($g['teacher']);
    $first = readEntry($notification->toArray(), false);
    $second = readEntry($notification->toArray(), false);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$first, $second]));

    expect($results[0])->toBe(['id' => $first['id'], 'status' => 'accepted', 'version' => 2]);
    expect($results[1])->toBe(['id' => $second['id'], 'status' => 'accepted', 'version' => 2]);
    expect(DB::table('sync_changes')->where('record_id', $notification->id)->where('version', 2)->count())->toBe(1);
    expect(DB::table('conflicts')->count())->toBe(0);
});

test('one device reads and another marks unread from the same base: a conflict with the current row, no conflict id, the row unchanged', function () {
    $g = buildGraph('School A', '-nt-opposed');
    $notification = notificationFor($g['teacher']);
    $read = readEntry($notification->toArray(), false);
    $unread = readEntry($notification->toArray(), true);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$read, $unread]));

    expect($results[0]['status'])->toBe('accepted');
    expect($results[1]['status'])->toBe('conflict');
    expect($results[1])->not->toHaveKey('conflictId');
    expect($results[1]['current']['fields']['unread'])->toBeFalse();
    expect(DB::table('notifications')->where('id', $notification->id)->value('unread'))->toBeFalse();
});

test('a colleague\'s notification at a nonzero base is forbidden and untouched', function () {
    $g = buildGraph('School A', '-nt-colleague');
    $colleague = makeColleague($g, 'colleague-nt@example.com');
    $theirs = notificationFor($colleague);
    $entry = readEntry($theirs->toArray(), false);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'forbidden']);
    expect(DB::table('notifications')->where('id', $theirs->id)->first())->toMatchArray(['unread' => true, 'version' => 1]);
});

test('a notification is never created from a device: a colleague\'s id and an unknown id at base 0 are invalid alike', function () {
    $g = buildGraph('School A', '-nt-create');
    $colleague = makeColleague($g, 'create-nt@example.com');
    $theirs = notificationFor($colleague);
    $before = DB::table('notifications')->count();
    $known = readEntry($theirs->toArray(), false, base: 0);
    $unknown = pushEntry('notifications', (string) Str::uuid7(), 0, ['unread' => false]);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$known, $unknown]));

    foreach ($results as $result) {
        expect($result['status'])->toBe('invalid');
        expect($result['reason'])->toBe('notifications are written by the server');
    }
    expect(DB::table('notifications')->count())->toBe($before);
});

test('another institution\'s notification at a nonzero base is forbidden', function () {
    $a = buildGraph('School A', '-nt-tenant-a');
    $b = buildGraph('School B', '-nt-tenant-b');
    $theirs = notificationFor($b['teacher']);
    $entry = readEntry($theirs->toArray(), false);

    $result = postedResults(pushEntries($this, tokenFor($a['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('forbidden');
    expect(DB::table('notifications')->where('id', $theirs->id)->value('unread'))->toBeTrue();
});

test('any field but unread is invalid', function (string $field) {
    $g = buildGraph('School A', '-nt-fields');
    $notification = notificationFor($g['teacher']);
    $entry = pushEntry('notifications', $notification->id, 1, [$field => 'x']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect(DB::table('notifications')->where('id', $notification->id)->value('version'))->toBe(1);
})->with(['title', 'kind', 'body', 'tone', 'userId', 'deletedAt']);

test('unread that is not a boolean is invalid at a stale base too, and the version does not move', function (mixed $value) {
    $g = buildGraph('School A', '-nt-bool-stale');
    $notification = notificationFor($g['teacher']);
    $notification->update(['unread' => false]);
    $entry = pushEntry('notifications', $notification->id, 1, ['unread' => $value]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'unread must be true or false']);
    expect(DB::table('notifications')->where('id', $notification->id)->value('version'))->toBe(2);
})->with(['the string false' => ['false'], 'zero' => [0], 'one' => [1], 'null' => [null], 'an array' => [[]]]);

test('unread that is not a boolean is invalid at the current version', function (mixed $value) {
    $g = buildGraph('School A', '-nt-bool-current');
    $notification = notificationFor($g['teacher']);
    $entry = pushEntry('notifications', $notification->id, 1, ['unread' => $value]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'unread must be true or false']);
    expect(DB::table('notifications')->where('id', $notification->id)->first())->toMatchArray(['unread' => true, 'version' => 1]);
})->with(['the string false' => ['false'], 'zero' => [0], 'one' => [1], 'null' => [null], 'an array' => [[]]]);

test('a soft-deleted own notification at the current version is invalid', function () {
    $g = buildGraph('School A', '-nt-deleted');
    $notification = notificationFor($g['teacher']);
    $notification->delete();
    $entry = readEntry($notification->toArray(), false, base: 2);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'the notification has been deleted']);
});

test('a resend is replayed, and a resent conflict reads the current row afresh', function () {
    $g = buildGraph('School A', '-nt-replay');
    $token = tokenFor($g['teacher']);
    $notification = notificationFor($g['teacher']);
    $read = readEntry($notification->toArray(), false);
    $unread = readEntry($notification->toArray(), true);

    $first = postedResults(pushEntries($this, $token, [$read, $unread]));
    app('auth')->forgetGuards();
    $again = postedResults(pushEntries($this, $token, [$read, $unread]));

    expect($again[0])->toBe($first[0] + ['replayed' => true]);
    expect($again[1]['status'])->toBe('conflict');
    expect($again[1]['replayed'])->toBeTrue();
    expect($again[1]['current']['version'])->toBe(2);
    expect(DB::table('sync_mutations')->whereIn('id', [$read['id'], $unread['id']])->count())->toBe(2);
});

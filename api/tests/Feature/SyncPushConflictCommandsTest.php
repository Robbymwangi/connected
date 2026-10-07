<?php

use App\Models\Conflict;
use App\Models\User;
use App\Support\CurrentInstitution;
use Illuminate\Support\Facades\DB;

/* 3.2c, F: the first two conflict commands over POST /sync, propose and refer (docs/spec/sync-protocol.md,
   "Conflict commands"; ADR 0002 and its 2026-10-07 amendment). They are commands on table `conflicts`, validated
   against the conflict's stored state and answered by the version rules only, never merged. The policy table has its
   own unit test (ConflictPolicyTest); these tests prove it is wired in the right order, that what is stored is
   rebuilt from validated pieces, and that nothing a device sends can reach the database unchecked.

   buildGraph's teacher moderates the subject, so in a conflict between two colleagues they are the moderator who is
   not a party; a conflict that involves them exercises the moderator who is a party. */

const COMMAND_STAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/';

/**
 * A graph, two plain colleagues, and an open conflict between them (side A the first, side B the second).
 *
 * @return array{0: array, 1: User, 2: User, 3: Conflict}
 */
function commandSetup(string $suffix): array
{
    $g = buildGraph('School A', $suffix);
    $a = makeColleague($g, "a{$suffix}@example.com");
    $b = makeColleague($g, "b{$suffix}@example.com");

    return [$g, $a, $b, openConflictBetween($g, $a, $b)];
}

/** Push one command as a user, at a base (the conflict's own version unless given); returns the one result. */
function commandPush(mixed $test, User $user, Conflict $conflict, array $fields, ?int $base = null, ?string $id = null, ?string $at = null): array
{
    app('auth')->forgetGuards();
    $entry = pushEntry('conflicts', $conflict->id, $base ?? (int) DB::table('conflicts')->where('id', $conflict->id)->value('version'), $fields, $id, $at ?? '2026-10-07T09:00:00Z');

    return postedResults(pushEntries($test, tokenFor($user), [$entry]))[0];
}

/** A proposal command as a user would send it, choosing a side by its editId. */
function proposal(User $by, array $choice, string $note = 'Checked the script again.'): array
{
    return ['proposal' => ['byId' => $by->id, 'choice' => $choice, 'note' => $note]];
}

function chooseSide(Conflict $conflict, string $side = 'side_a'): array
{
    return ['kind' => 'side', 'editId' => $conflict->{$side}['editId']];
}

/** The stored conflict, with its JSON columns decoded. */
function storedConflict(Conflict $conflict): array
{
    $row = (array) DB::table('conflicts')->find($conflict->id);

    foreach (['side_a', 'side_b', 'proposals', 'referral', 'resolution'] as $column) {
        $row[$column] = $row[$column] === null ? null : json_decode($row[$column], true);
    }

    return $row;
}

/* The order of checks that every command shares. */

test('a conflict is raised by the server: an unknown id is invalid at base 0 and forbidden at a later base', function () {
    [$g, $a, $b] = commandSetup('-cc-unknown');
    $ghost = new Conflict(['id' => (string) Str::uuid()]);

    $created = commandPush($this, $a, $ghost, proposal($a, ['kind' => 'side', 'editId' => (string) Str::uuid()]), base: 0);
    $later = commandPush($this, $a, $ghost, proposal($a, ['kind' => 'side', 'editId' => (string) Str::uuid()]), base: 1);

    expect($created)->toMatchArray(['status' => 'invalid', 'reason' => 'conflicts are raised by the server']);
    expect($later['status'])->toBe('forbidden');
    expect(DB::table('conflicts')->count())->toBe(1);
});

test('a conflict in another institution is as if it did not exist', function () {
    [, $a, $b, $conflict] = commandSetup('-cc-other-inst');
    $other = buildGraph('School B', '-cc-other-inst-b');
    app(CurrentInstitution::class)->reset();

    $result = commandPush($this, $other['teacher'], $conflict, proposal($other['teacher'], chooseSide($conflict)), base: 1);

    expect($result['status'])->toBe('forbidden');
    expect(storedConflict($conflict)['version'])->toBe(1);
});

test('someone who is neither a party nor a moderator of the subject is forbidden, whatever they send', function () {
    [$g, , , $conflict] = commandSetup('-cc-outsider');
    $outsider = makeColleague($g, 'outsider-cc@example.com');

    expect(commandPush($this, $outsider, $conflict, proposal($outsider, chooseSide($conflict)))['status'])->toBe('forbidden');
    expect(commandPush($this, $outsider, $conflict, ['referral' => ['byId' => $outsider->id]])['status'])->toBe('forbidden');
    expect(commandPush($this, $outsider, $conflict, ['proposal' => 'nonsense'])['status'])->toBe('forbidden');
    expect(storedConflict($conflict)['version'])->toBe(1);
});

test('authorization is answered before the command is validated: a moderator who is not a party is forbidden, not invalid', function () {
    [$g, , , $conflict] = commandSetup('-cc-moderator');

    $result = commandPush($this, $g['teacher'], $conflict, ['proposal' => 'nonsense']);

    expect($result['status'])->toBe('forbidden');
});

test('a base ahead of the conflict is invalid, and a base behind is a conflict carrying the conflict itself', function () {
    [, $a, $b, $conflict] = commandSetup('-cc-bases');

    $ahead = commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)), base: 5);
    expect($ahead)->toMatchArray(['status' => 'invalid', 'reason' => 'baseVersion is ahead of the server']);

    expect(commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)))['status'])->toBe('accepted');

    // B had not seen A's proposal: still at base 1.
    $behind = commandPush($this, $b, $conflict, proposal($b, chooseSide($conflict, 'side_b')), base: 1);

    expect($behind['status'])->toBe('conflict');
    expect($behind)->not->toHaveKey('conflictId');
    expect($behind['current']['version'])->toBe(2);
    expect($behind['current']['fields']['proposals'])->toHaveCount(1);
    expect(storedConflict($conflict)['proposals'])->toHaveCount(1);
});

test('a state that would be invalid is answered conflict when the base is behind: state is checked only at an equal version', function () {
    [, $a, $b, $conflict] = commandSetup('-cc-state-after-version');
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));

    // A proposing again would be invalid (their own proposal is pending), but A is a version behind: the fresh conflict comes first.
    $stale = commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)), base: 1);

    expect($stale['status'])->toBe('conflict');
});

test('the fields a command may never carry are refused by name', function (array $fields, string $reason) {
    [, $a, , $conflict] = commandSetup('-cc-refused-'.md5($reason));

    $result = commandPush($this, $a, $conflict, $fields);

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toContain($reason);
    expect(storedConflict($conflict)['version'])->toBe(1);
})->with([
    'the proposals array' => [['proposals' => []], 'one proposal'],
    'a resolved time' => [['resolvedAt' => '2026-10-07T09:00:00Z'], 'server'],
    'a received time' => [['receivedAt' => '2026-10-07T09:00:00Z'], 'server-owned'],
    'a side' => [['sideA' => []], 'unknown field'],
    'a resolution, which is not accepted yet' => [['resolution' => ['kind' => 'self']], 'not accepted yet'],
]);

test('exactly one command per entry', function () {
    [, $a, , $conflict] = commandSetup('-cc-one-command');

    $both = commandPush($this, $a, $conflict, [...proposal($a, chooseSide($conflict)), 'referral' => ['byId' => $a->id]]);

    expect($both['status'])->toBe('invalid');
    expect(storedConflict($conflict)['version'])->toBe(1);
});

test('a conflict whose mark the scope hides is invalid, never a server error', function () {
    [$g, $a, , $conflict] = commandSetup('-cc-hidden-mark');
    $other = buildGraph('School B', '-cc-hidden-mark-b');
    DB::table('conflicts')->where('id', $conflict->id)->update(['mark_id' => $other['mark']->id]);
    app(CurrentInstitution::class)->reset();

    $result = commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));

    expect($result)->toMatchArray(['status' => 'invalid']);
});

/* Propose. */

test('a proposal is stored rebuilt from validated pieces, logged, linked to its mutation, and replayed on a resend', function () {
    [, $a, , $conflict] = commandSetup('-cc-propose');
    $id = (string) Str::uuid7();

    $result = commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict), 'Re-marked from the script.'), id: $id, at: '2026-10-07T09:30:00Z');

    expect($result)->toMatchArray(['status' => 'accepted', 'version' => 2]);
    $stored = storedConflict($conflict);
    expect($stored['version'])->toBe(2);
    expect($stored['proposals'])->toHaveCount(1);
    expect($stored['proposals'][0])->toMatchArray([
        'byId' => $a->id,
        'by' => $a->name,
        'choice' => ['kind' => 'side', 'editId' => $conflict->side_a['editId']],
        'note' => 'Re-marked from the script.',
        'at' => '2026-10-07T09:30:00Z',
    ]);
    expect($stored['proposals'][0]['receivedAt'])->toMatch(COMMAND_STAMP);
    expect($stored['resolution'])->toBeNull();

    $change = DB::table('sync_changes')->where('table', 'conflicts')->where('record_id', $conflict->id)->where('version', 2)->first();
    expect(json_decode($change->fields, true))->toHaveKey('proposals');
    expect((int) DB::table('sync_mutations')->where('id', $id)->value('change_seq'))->toBe($change->seq);
    expect(DB::table('sync_mutations')->where('id', $id)->first())->toMatchArray(['status' => 'accepted', 'version' => 2, 'conflict_id' => null]);

    $again = commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict), 'Re-marked from the script.'), base: 1, id: $id, at: '2026-10-07T10:00:00Z');
    expect($again)->toMatchArray(['status' => 'accepted', 'version' => 2, 'replayed' => true]);
    expect(storedConflict($conflict)['proposals'])->toHaveCount(1);
});

test('the other party counters, and the two proposals are kept in order', function () {
    [, $a, $b, $conflict] = commandSetup('-cc-counter');
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));

    $counter = commandPush($this, $b, $conflict, proposal($b, ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => 8]], 'Neither; I make it 8.'));

    expect($counter)->toMatchArray(['status' => 'accepted', 'version' => 3]);
    $proposals = storedConflict($conflict)['proposals'];
    expect(array_column($proposals, 'byId'))->toBe([$a->id, $b->id]);
    expect($proposals[1]['choice'])->toBe(['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => 8]]);
});

test('a corrected absent mark is a valid choice, and a score at the criterion maximum or zero is too', function (array $mark) {
    [, $a, , $conflict] = commandSetup('-cc-corrected-'.md5(json_encode($mark)));

    $result = commandPush($this, $a, $conflict, proposal($a, ['kind' => 'corrected', 'mark' => $mark]));

    expect($result['status'])->toBe('accepted');
    expect(storedConflict($conflict)['proposals'][0]['choice']['mark'])->toBe($mark);
})->with([
    'absent' => [['kind' => 'absent']],
    'zero' => [['kind' => 'score', 'value' => 0]],
    'the maximum' => [['kind' => 'score', 'value' => 10]],
]);

test('a note of exactly 2000 characters, counted as characters, is accepted', function () {
    [, $a, , $conflict] = commandSetup('-cc-note-limit');

    $result = commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict), str_repeat('é', 2000)));

    expect($result['status'])->toBe('accepted');
});

test('a malformed proposal is invalid and writes nothing', function (callable $fields) {
    [, $a, $b, $conflict] = commandSetup('-cc-poison-'.spl_object_id($fields));
    $fields = $fields($a, $b, $conflict);

    $result = commandPush($this, $a, $conflict, $fields);

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBeString()->not->toBe('');
    expect(storedConflict($conflict)['version'])->toBe(1);
    expect(storedConflict($conflict)['proposals'])->toBe([]);
})->with([
    'a proposal that is not an object' => [fn ($a, $b, $c) => ['proposal' => 'nonsense']],
    'a proposal that is a list' => [fn ($a, $b, $c) => ['proposal' => [1, 2]]],
    'byId of someone else' => [fn ($a, $b, $c) => ['proposal' => ['byId' => $b->id, 'choice' => chooseSide($c), 'note' => 'x']]],
    'byId that is not a string' => [fn ($a, $b, $c) => ['proposal' => ['byId' => 7, 'choice' => chooseSide($c), 'note' => 'x']]],
    'a missing note' => [fn ($a, $b, $c) => ['proposal' => ['byId' => $a->id, 'choice' => chooseSide($c)]]],
    'a missing choice' => [fn ($a, $b, $c) => ['proposal' => ['byId' => $a->id, 'note' => 'x']]],
    'a name the server owns, `by`' => [fn ($a, $b, $c) => ['proposal' => ['byId' => $a->id, 'by' => 'Z', 'choice' => chooseSide($c), 'note' => 'x']]],
    'a time inside the proposal, `at`' => [fn ($a, $b, $c) => ['proposal' => ['byId' => $a->id, 'at' => 'x', 'choice' => chooseSide($c), 'note' => 'x']]],
    'receivedAt inside the proposal' => [fn ($a, $b, $c) => ['proposal' => ['byId' => $a->id, 'receivedAt' => 'x', 'choice' => chooseSide($c), 'note' => 'x']]],
    'receivedAt inside the choice' => [fn ($a, $b, $c) => ['proposal' => ['byId' => $a->id, 'choice' => [...chooseSide($c), 'receivedAt' => 'x'], 'note' => 'x']]],
    'a blank note' => [fn ($a, $b, $c) => proposal($a, chooseSide($c), "  \t ")],
    'a note that is not a string' => [fn ($a, $b, $c) => ['proposal' => ['byId' => $a->id, 'choice' => chooseSide($c), 'note' => ['x']]]],
    'a note with a NUL in it' => [fn ($a, $b, $c) => proposal($a, chooseSide($c), "bad\0note")],
    'a note of 2001 characters' => [fn ($a, $b, $c) => proposal($a, chooseSide($c), str_repeat('é', 2001))],
    'a choice that is not an object' => [fn ($a, $b, $c) => proposal($a, ['x'])],
    'an unknown choice kind' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'both'])],
    'a side the conflict does not have' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'side', 'editId' => (string) Str::uuid()])],
    'an editId that is not a string' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'side', 'editId' => 5])],
    'an editId that is far too long' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'side', 'editId' => str_repeat('x', 100000)])],
    'an editId with a NUL' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'side', 'editId' => "a\0b"])],
    'a side choice with an extra key' => [fn ($a, $b, $c) => proposal($a, [...chooseSide($c), 'score' => 1])],
    'a corrected choice without a mark' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected'])],
    'a corrected empty mark' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected', 'mark' => ['kind' => 'empty']])],
    'a corrected score that is a float' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => 7.5]])],
    'a corrected score that is a numeric string' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => '7']])],
    'a corrected score that is a boolean' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => true]])],
    'a corrected score below zero' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => -1]])],
    'a corrected score above the criterion maximum' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => 11]])],
    'a corrected score that overflows an integer' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => 99999999999999999999]])],
    'a corrected absent with a value' => [fn ($a, $b, $c) => proposal($a, ['kind' => 'corrected', 'mark' => ['kind' => 'absent', 'value' => 3]])],
]);

test('the policy is wired in: a self author, a proposer waiting, a third proposal, a referred conflict, and a resolved one', function () {
    [$g, $a, $b, $conflict] = commandSetup('-cc-policy');
    $self = openConflictBetween($g, $a, $a);

    expect(commandPush($this, $a, $self, proposal($a, chooseSide($self)))['status'])->toBe('invalid');

    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));
    expect(commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)))['status'])->toBe('invalid');

    commandPush($this, $b, $conflict, proposal($b, chooseSide($conflict, 'side_b')));
    expect(commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)))['status'])->toBe('invalid');
    expect(storedConflict($conflict)['proposals'])->toHaveCount(2);

    $referred = openConflictBetween($g, $a, $b, ['referral' => ['reason' => 'party', 'byId' => $b->id, 'by' => $b->name, 'at' => null, 'receivedAt' => '2026-10-07T08:30:00.000000Z']]);
    expect(commandPush($this, $a, $referred, proposal($a, chooseSide($referred)))['status'])->toBe('invalid');

    $resolved = openConflictBetween($g, $a, $b, ['resolution' => ['kind' => 'auto'], 'resolved_at' => now()]);
    expect(commandPush($this, $a, $resolved, proposal($a, chooseSide($resolved)))['status'])->toBe('invalid');
});

test('a resend with an edited note is a different payload under a used id, and is invalid', function () {
    [, $a, , $conflict] = commandSetup('-cc-edited-note');
    $id = (string) Str::uuid7();
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict), 'First wording.'), id: $id);

    $result = commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict), 'Second wording.'), id: $id);

    expect($result)->toMatchArray(['status' => 'invalid', 'reason' => 'mutation id was already used with a different payload']);
    expect(storedConflict($conflict)['proposals'][0]['note'])->toBe('First wording.');
});

test('a moderator who is also a party proposes as a party', function () {
    [$g, $a] = commandSetup('-cc-moderator-party');
    $conflict = openConflictBetween($g, $g['teacher'], $a);

    $result = commandPush($this, $g['teacher'], $conflict, proposal($g['teacher'], chooseSide($conflict)));

    expect($result['status'])->toBe('accepted');
});

/* Refer. */

test('a party refers a conflict before anything is pending: the reason is party, with who, and the subject\'s moderators are told', function () {
    [$g, $a, , $conflict] = commandSetup('-cc-refer');
    $moderator = makeModerator($g, 'm-cc-refer@example.com');
    $id = (string) Str::uuid7();

    $result = commandPush($this, $a, $conflict, ['referral' => ['byId' => $a->id]], id: $id, at: '2026-10-07T09:45:00Z');

    expect($result)->toMatchArray(['status' => 'accepted', 'version' => 2]);
    $referral = storedConflict($conflict)['referral'];
    expect($referral)->toMatchArray(['reason' => 'party', 'byId' => $a->id, 'by' => $a->name, 'at' => '2026-10-07T09:45:00Z']);
    expect($referral['receivedAt'])->toMatch(COMMAND_STAMP);
    expect(DB::table('sync_mutations')->where('id', $id)->value('change_seq'))->not->toBeNull();

    foreach ([$g['teacher'], $moderator] as $notified) {
        $notices = DB::table('notifications')->where('user_id', $notified->id)->where('title', 'Conflict referred to you')->get();
        expect($notices)->toHaveCount(1);
        expect($notices[0]->assessment_id)->toBe($g['assessment']->id);
    }

    // The parties are told nothing by a referral.
    expect(DB::table('notifications')->where('user_id', $a->id)->count())->toBe(0);
});

test('a resend of a referral replays and writes no second notification', function () {
    [$g, $a, , $conflict] = commandSetup('-cc-refer-replay');
    $id = (string) Str::uuid7();
    commandPush($this, $a, $conflict, ['referral' => ['byId' => $a->id]], id: $id);
    $notices = DB::table('notifications')->count();

    $again = commandPush($this, $a, $conflict, ['referral' => ['byId' => $a->id]], base: 1, id: $id);

    expect($again)->toMatchArray(['status' => 'accepted', 'replayed' => true]);
    expect(DB::table('notifications')->count())->toBe($notices);
    expect(storedConflict($conflict)['version'])->toBe(2);
});

test('after a proposal and a counter, the original proposer refers for rounds, naming no one', function () {
    [, $a, $b, $conflict] = commandSetup('-cc-refer-rounds');
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));
    commandPush($this, $b, $conflict, proposal($b, chooseSide($conflict, 'side_b')));

    $result = commandPush($this, $a, $conflict, ['referral' => ['byId' => $a->id]], at: '2026-10-07T10:15:00Z');

    expect($result['status'])->toBe('accepted');
    $referral = storedConflict($conflict)['referral'];
    expect(array_keys($referral))->toEqualCanonicalizing(['reason', 'at', 'receivedAt']);
    expect($referral['reason'])->toBe('rounds');
    expect($referral['at'])->toBe('2026-10-07T10:15:00Z');
});

test('the author of the counter may not refer, and nobody may refer twice', function () {
    [, $a, $b, $conflict] = commandSetup('-cc-refer-bounds');
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));
    commandPush($this, $b, $conflict, proposal($b, chooseSide($conflict, 'side_b')));

    $counterAuthor = commandPush($this, $b, $conflict, ['referral' => ['byId' => $b->id]]);
    expect($counterAuthor['status'])->toBe('invalid');
    expect(storedConflict($conflict)['referral'])->toBeNull();

    expect(commandPush($this, $a, $conflict, ['referral' => ['byId' => $a->id]])['status'])->toBe('accepted');
    expect(commandPush($this, $a, $conflict, ['referral' => ['byId' => $a->id]])['status'])->toBe('invalid');
});

test('a malformed referral is invalid and writes nothing', function (callable $fields) {
    [, $a, $b, $conflict] = commandSetup('-cc-refer-poison-'.spl_object_id($fields));

    $result = commandPush($this, $a, $conflict, $fields($a, $b));

    expect($result['status'])->toBe('invalid');
    expect(storedConflict($conflict)['version'])->toBe(1);
    expect(DB::table('notifications')->count())->toBe(0);
})->with([
    'not an object' => [fn ($a, $b) => ['referral' => 'now']],
    'byId of someone else' => [fn ($a, $b) => ['referral' => ['byId' => $b->id]]],
    'no byId' => [fn ($a, $b) => ['referral' => ['x' => 1]]],
    'a reason sent by the device' => [fn ($a, $b) => ['referral' => ['byId' => $a->id, 'reason' => 'rounds']]],
    'a time inside the referral' => [fn ($a, $b) => ['referral' => ['byId' => $a->id, 'receivedAt' => 'x']]],
]);

test('a moderator who is a party refers as a party, and the other moderators are told, not them', function () {
    [$g, $a] = commandSetup('-cc-refer-moderator-party');
    $other = makeModerator($g, 'other-cc-refer@example.com');
    $conflict = openConflictBetween($g, $g['teacher'], $a);

    $result = commandPush($this, $g['teacher'], $conflict, ['referral' => ['byId' => $g['teacher']->id]]);

    expect($result['status'])->toBe('accepted');
    expect(storedConflict($conflict)['referral']['reason'])->toBe('party');
    expect(DB::table('notifications')->where('user_id', $other->id)->count())->toBe(1);
    expect(DB::table('notifications')->where('user_id', $g['teacher']->id)->count())->toBe(0);
});

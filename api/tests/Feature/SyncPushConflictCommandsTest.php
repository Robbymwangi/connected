<?php

use App\Models\Conflict;
use App\Models\User;
use App\Support\CurrentInstitution;
use App\Sync\Push\MarkConflicts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/* 3.2c, F and G: the four conflict commands over POST /sync. F: propose and refer (docs/spec/sync-protocol.md,
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

/* Resolve and accept (3.2c, G). A resolution is a command like the others (validated against the stored state, never
   merged), and it also writes the mark. The write is a stale write at the mark's version when the conflict was raised
   (conflicts.mark_version), decided by rule 4: nothing moved applies it, the cell already holding it writes nothing,
   and anything else records the resolution, leaves the mark, and raises a follow-up conflict. */

/** A graph and two plain colleagues, with no conflict yet, for tests that raise real ones. @return array{0: array, 1: User, 2: User} */
function resolveSetup(string $suffix): array
{
    $g = buildGraph('School A', $suffix);

    return [$g, makeColleague($g, "a{$suffix}@example.com"), makeColleague($g, "b{$suffix}@example.com")];
}

/** An array with its keys sorted at every depth: jsonb does not keep key order, so stored objects compare by this. */
function keySorted(array $value): array
{
    ksort($value);

    return array_map(fn ($inner) => is_array($inner) ? keySorted($inner) : $inner, $value);
}

/**
 * A real mark conflict between two users, raised by the server from two pushes: A scores 6 and B scores 9, both from
 * base 1. The mark is at version 2 holding A's 6; the conflict's mark_version is 2, side A is A's mutation.
 */
function realConflictBetween(mixed $test, array $graph, User $a, User $b, int $aScore = 6, int $bScore = 9): Conflict
{
    postedResults(pushEntries($test, tokenFor($a), [pushEntry('marks', $graph['mark']->id, 1, ['score' => $aScore])]));
    app('auth')->forgetGuards();
    postedResults(pushEntries($test, tokenFor($b), [pushEntry('marks', $graph['mark']->id, 1, ['score' => $bScore])]));
    app('auth')->forgetGuards();

    return Conflict::query()->where('mark_id', $graph['mark']->id)->orderByDesc('id')->firstOrFail();
}

function resolution(array $body): array
{
    return ['resolution' => $body];
}

function selfResolution(User $by, array $choice): array
{
    return resolution(['kind' => 'self', 'byId' => $by->id, 'choice' => $choice]);
}

function moderatedResolution(User $by, array $choice, string $note = 'The script shows this.'): array
{
    return resolution(['kind' => 'moderated', 'byId' => $by->id, 'choice' => $choice, 'note' => $note]);
}

function agreedResolution(User $proposer, User $acceptor): array
{
    return resolution(['kind' => 'agreed', 'proposedById' => $proposer->id, 'acceptedById' => $acceptor->id]);
}

function correctedTo(int $value): array
{
    return ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => $value]];
}

/** The mark row as stored. */
function storedMark(array $graph): array
{
    return (array) DB::table('marks')->find($graph['mark']->id);
}

function markLogRows(array $graph): int
{
    return DB::table('sync_changes')->where('table', 'marks')->where('record_id', $graph['mark']->id)->count();
}

/** A user in the same institution who moderates the subject and is a party to nothing. */
function moderatorOf(array $graph, string $suffix): User
{
    return makeModerator($graph, "mod{$suffix}@example.com");
}

/* Refusals: nothing is written, whatever is wrong. */

test('a malformed self resolution is invalid and writes nothing', function (callable $fields) {
    [$g, $a] = resolveSetup('-rs-self-'.spl_object_id($fields));
    $self = realConflictBetween($this, $g, $a, $a);
    $marksBefore = storedMark($g);

    $result = commandPush($this, $a, $self, $fields($a, $self));

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBeString()->not->toBe('');
    expect(storedConflict($self)['version'])->toBe(1);
    expect(storedConflict($self)['resolution'])->toBeNull();
    expect(storedMark($g))->toBe($marksBefore);
})->with([
    'a note key, which a self resolution never carries' => [fn ($a, $c) => resolution(['kind' => 'self', 'byId' => $a->id, 'choice' => chooseSide($c), 'note' => 'x'])],
    'no choice' => [fn ($a, $c) => resolution(['kind' => 'self', 'byId' => $a->id])],
    'no byId' => [fn ($a, $c) => resolution(['kind' => 'self', 'choice' => chooseSide($c)])],
    'an extra key' => [fn ($a, $c) => resolution(['kind' => 'self', 'byId' => $a->id, 'choice' => chooseSide($c), 'by' => 'Me'])],
    'receivedAt nested in the choice' => [fn ($a, $c) => resolution(['kind' => 'self', 'byId' => $a->id, 'choice' => [...chooseSide($c), 'receivedAt' => 'x']])],
    'a side the conflict does not have' => [fn ($a, $c) => selfResolution($a, ['kind' => 'side', 'editId' => (string) Str::uuid()])],
    'a corrected score above the maximum' => [fn ($a, $c) => selfResolution($a, correctedTo(11))],
    'a corrected score that is a float' => [fn ($a, $c) => selfResolution($a, ['kind' => 'corrected', 'mark' => ['kind' => 'score', 'value' => 7.5]])],
    'a corrected empty mark' => [fn ($a, $c) => selfResolution($a, ['kind' => 'corrected', 'mark' => ['kind' => 'empty']])],
    'a resolution that is not an object' => [fn ($a, $c) => ['resolution' => 'self']],
    'a resolution that is a list' => [fn ($a, $c) => ['resolution' => ['self']]],
    'a kind a device may not send, auto' => [fn ($a, $c) => resolution(['kind' => 'auto'])],
    'an unknown kind' => [fn ($a, $c) => resolution(['kind' => 'forced', 'byId' => $a->id, 'choice' => chooseSide($c)])],
    'a kind that is not a string' => [fn ($a, $c) => resolution(['kind' => ['self']])],
]);

test('a self resolution by someone else\'s id is invalid', function () {
    [$g, $a, $b] = resolveSetup('-rs-self-by');
    $self = realConflictBetween($this, $g, $a, $a);

    $result = commandPush($this, $a, $self, resolution(['kind' => 'self', 'byId' => $b->id, 'choice' => chooseSide($self)]));

    expect($result['status'])->toBe('invalid');
});

test('a malformed moderated resolution is invalid and writes nothing', function (callable $fields) {
    [$g, $a, $b] = resolveSetup('-rs-mod-'.spl_object_id($fields));
    $conflict = realConflictBetween($this, $g, $a, $b);
    $moderator = moderatorOf($g, '-rs-mod-'.spl_object_id($fields));
    $marksBefore = storedMark($g);

    $result = commandPush($this, $moderator, $conflict, $fields($moderator, $conflict, $a));

    expect($result['status'])->toBe('invalid');
    expect(storedConflict($conflict)['version'])->toBe(1);
    expect(storedConflict($conflict)['resolution'])->toBeNull();
    expect(storedMark($g))->toBe($marksBefore);
})->with([
    'no note' => [fn ($m, $c) => resolution(['kind' => 'moderated', 'byId' => $m->id, 'choice' => chooseSide($c)])],
    'a blank note' => [fn ($m, $c) => moderatedResolution($m, chooseSide($c), '   ')],
    'a note with a NUL' => [fn ($m, $c) => moderatedResolution($m, chooseSide($c), "a\0b")],
    'a note of 2001 characters' => [fn ($m, $c) => moderatedResolution($m, chooseSide($c), str_repeat('é', 2001))],
    'a note that is not a string' => [fn ($m, $c) => resolution(['kind' => 'moderated', 'byId' => $m->id, 'choice' => chooseSide($c), 'note' => 5])],
    'byId of someone else' => [fn ($m, $c, $a) => resolution(['kind' => 'moderated', 'byId' => $a->id, 'choice' => chooseSide($c), 'note' => 'x'])],
    'an extra key' => [fn ($m, $c) => resolution(['kind' => 'moderated', 'byId' => $m->id, 'choice' => chooseSide($c), 'note' => 'x', 'by' => 'Me'])],
    'a side the conflict does not have' => [fn ($m, $c) => moderatedResolution($m, ['kind' => 'side', 'editId' => 'nope'])],
    'a corrected score below zero' => [fn ($m, $c) => moderatedResolution($m, correctedTo(-1))],
]);

test('a malformed agreement is invalid and writes nothing', function (callable $fields) {
    [$g, $a, $b] = resolveSetup('-rs-agreed-'.spl_object_id($fields));
    $conflict = realConflictBetween($this, $g, $a, $b);
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));
    $marksBefore = storedMark($g);

    $result = commandPush($this, $b, $conflict, $fields($a, $b, $conflict));

    expect($result['status'])->toBe('invalid');
    expect(storedConflict($conflict)['version'])->toBe(2);
    expect(storedConflict($conflict)['resolution'])->toBeNull();
    expect(storedMark($g))->toBe($marksBefore);
})->with([
    'no proposedById' => [fn ($a, $b) => resolution(['kind' => 'agreed', 'acceptedById' => $b->id])],
    'no acceptedById' => [fn ($a, $b) => resolution(['kind' => 'agreed', 'proposedById' => $a->id])],
    'a choice the device must not relay' => [fn ($a, $b, $c) => resolution(['kind' => 'agreed', 'proposedById' => $a->id, 'acceptedById' => $b->id, 'choice' => chooseSide($c)])],
    'a note the device must not relay' => [fn ($a, $b) => resolution(['kind' => 'agreed', 'proposedById' => $a->id, 'acceptedById' => $b->id, 'note' => 'x'])],
    'acceptedById of someone else' => [fn ($a, $b) => resolution(['kind' => 'agreed', 'proposedById' => $a->id, 'acceptedById' => $a->id])],
    'acceptedById that is not a string' => [fn ($a, $b) => resolution(['kind' => 'agreed', 'proposedById' => $a->id, 'acceptedById' => 9])],
    'proposedById that is not a string' => [fn ($a, $b) => resolution(['kind' => 'agreed', 'proposedById' => ['x'], 'acceptedById' => $b->id])],
    'a proposedById that is not the pending proposer' => [fn ($a, $b) => resolution(['kind' => 'agreed', 'proposedById' => $b->id, 'acceptedById' => $b->id])],
    'a huge proposedById' => [fn ($a, $b) => resolution(['kind' => 'agreed', 'proposedById' => str_repeat('x', 100000), 'acceptedById' => $b->id])],
]);

test('a stale acceptance whose proposedById is wrong is answered with the conflict, not invalid: state is checked at an equal version only', function () {
    [$g, $a, $b] = resolveSetup('-rs-agreed-stale');
    $conflict = realConflictBetween($this, $g, $a, $b);
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));

    $result = commandPush($this, $b, $conflict, resolution(['kind' => 'agreed', 'proposedById' => $b->id, 'acceptedById' => $b->id]), base: 1);

    expect($result['status'])->toBe('conflict');
});

test('who may send which resolution: the actor rules come first, then whether the kind fits', function () {
    [$g, $a, $b] = resolveSetup('-rs-matrix');
    $self = realConflictBetween($this, $g, $a, $a);
    $cross = openConflictBetween($g, $a, $b);
    $moderator = moderatorOf($g, '-rs-matrix');
    $partyModerator = makeModerator($g, 'party-mod-rs-matrix@example.com');
    $partyConflict = openConflictBetween($g, $partyModerator, $b);
    commandPush($this, $b, $partyConflict, proposal($b, chooseSide($partyConflict, 'side_b')));

    // A party never resolves directly, a moderator who is a party included.
    expect(commandPush($this, $a, $cross, selfResolution($a, chooseSide($cross)))['status'])->toBe('forbidden');
    expect(commandPush($this, $a, $cross, moderatedResolution($a, chooseSide($cross)))['status'])->toBe('forbidden');
    expect(commandPush($this, $partyModerator, $partyConflict, moderatedResolution($partyModerator, chooseSide($partyConflict)))['status'])->toBe('forbidden');
    // A moderator may neither propose-side commands nor touch someone's conflict with themself.
    expect(commandPush($this, $moderator, $cross, agreedResolution($a, $moderator))['status'])->toBe('forbidden');
    expect(commandPush($this, $moderator, $self, moderatedResolution($moderator, chooseSide($self)))['status'])->toBe('forbidden');
    // Roles that may resolve, with the wrong kind.
    expect(commandPush($this, $a, $self, agreedResolution($a, $a))['status'])->toBe('invalid');
    expect(commandPush($this, $a, $self, moderatedResolution($a, chooseSide($self)))['status'])->toBe('invalid');
    expect(commandPush($this, $moderator, $cross, selfResolution($moderator, chooseSide($cross)))['status'])->toBe('invalid');
    expect(DB::table('conflicts')->whereNotNull('resolution')->count())->toBe(0);
});

test('an acceptance with nothing pending, with the acceptor\'s own proposal pending, or on a referred conflict is invalid', function () {
    [$g, $a, $b] = resolveSetup('-rs-accept-state');
    $conflict = openConflictBetween($g, $a, $b);

    expect(commandPush($this, $b, $conflict, agreedResolution($a, $b))['status'])->toBe('invalid');

    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));
    expect(commandPush($this, $a, $conflict, agreedResolution($a, $a))['status'])->toBe('invalid');

    commandPush($this, $b, $conflict, ['referral' => ['byId' => $b->id]]);
    expect(commandPush($this, $b, $conflict, agreedResolution($a, $b))['status'])->toBe('invalid');
    expect(storedConflict($conflict)['resolution'])->toBeNull();
});

test('a resolved conflict accepts nothing more, whoever sends it', function () {
    [$g, $a, $b] = resolveSetup('-rs-resolved');
    $moderator = moderatorOf($g, '-rs-resolved');
    $conflict = realConflictBetween($this, $g, $a, $b);
    expect(commandPush($this, $moderator, $conflict, moderatedResolution($moderator, chooseSide($conflict)))['status'])->toBe('accepted');

    expect(commandPush($this, $moderator, $conflict, moderatedResolution($moderator, chooseSide($conflict)))['status'])->toBe('invalid');
    expect(commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)))['status'])->toBe('invalid');
    expect(commandPush($this, $a, $conflict, ['referral' => ['byId' => $a->id]])['status'])->toBe('invalid');
});

/* The write. */

test('a self resolution writes the chosen side to the mark, credits that side\'s user, and logs the mark and the conflict', function () {
    [$g, $a] = resolveSetup('-rs-write');
    $self = realConflictBetween($this, $g, $a, $a);
    $markLog = markLogRows($g);
    $id = (string) Str::uuid7();

    $result = commandPush($this, $a, $self, selfResolution($a, chooseSide($self, 'side_b')), id: $id, at: '2026-10-07T11:00:00Z');

    expect($result)->toBe(['id' => $id, 'status' => 'accepted', 'version' => 2]);
    expect(storedMark($g))->toMatchArray(['mark_kind' => 'score', 'score' => 9, 'last_edited_by' => $a->id, 'version' => 3]);
    expect(markLogRows($g))->toBe($markLog + 1);

    $stored = storedConflict($self);
    expect($stored['version'])->toBe(2);
    expect(keySorted($stored['resolution']))->toBe(keySorted(['kind' => 'self', 'byId' => $a->id, 'by' => $a->name, 'choice' => ['kind' => 'side', 'editId' => $self->side_b['editId']], 'at' => '2026-10-07T11:00:00Z']));
    expect($stored['resolved_at'])->not->toBeNull();

    $conflictLog = DB::table('sync_changes')->where('table', 'conflicts')->where('record_id', $self->id)->where('version', 2)->first();
    expect(array_keys(json_decode($conflictLog->fields, true)))->toEqualCanonicalizing(['resolution', 'resolved_at']);
    $mutation = DB::table('sync_mutations')->where('id', $id)->first();
    expect($mutation->change_seq)->toBe($conflictLog->seq);
    expect($mutation)->toMatchArray(['status' => 'accepted', 'version' => 2, 'conflict_id' => null]);
});

test('a side choice credits that side\'s user, not the moderator who chose it', function () {
    [$g, $a, $b] = resolveSetup('-rs-credit-side');
    $moderator = moderatorOf($g, '-rs-credit-side');
    $conflict = realConflictBetween($this, $g, $a, $b);

    commandPush($this, $moderator, $conflict, moderatedResolution($moderator, chooseSide($conflict, 'side_b')));

    expect(storedMark($g))->toMatchArray(['score' => 9, 'last_edited_by' => $b->id]);
});

test('a corrected value from a moderator is credited to the moderator', function () {
    [$g, $a, $b] = resolveSetup('-rs-credit-corrected');
    $moderator = moderatorOf($g, '-rs-credit-corrected');
    $conflict = realConflictBetween($this, $g, $a, $b, aScore: 4, bScore: 5);

    commandPush($this, $moderator, $conflict, moderatedResolution($moderator, correctedTo(7)));

    expect(storedMark($g))->toMatchArray(['score' => 7, 'last_edited_by' => $moderator->id]);
});

test('a self resolution with a corrected value credits the author', function () {
    [$g, $a] = resolveSetup('-rs-credit-self');
    $self = realConflictBetween($this, $g, $a, $a);

    commandPush($this, $a, $self, selfResolution($a, correctedTo(3)));

    expect(storedMark($g))->toMatchArray(['score' => 3, 'last_edited_by' => $a->id]);
});

test('an agreement copies the choice and note from the stored proposal, and credits the acceptor for a corrected value', function () {
    [$g, $a, $b] = resolveSetup('-rs-agreed');
    $conflict = realConflictBetween($this, $g, $a, $b);
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict)));
    commandPush($this, $b, $conflict, proposal($b, correctedTo(8), 'Neither; it is an 8.'));

    $result = commandPush($this, $a, $conflict, agreedResolution($b, $a), at: '2026-10-07T12:00:00Z');

    expect($result['status'])->toBe('accepted');
    $stored = storedConflict($conflict);
    expect(keySorted($stored['resolution']))->toBe(keySorted([
        'kind' => 'agreed',
        'proposedById' => $b->id, 'proposedBy' => $b->name,
        'acceptedById' => $a->id, 'acceptedBy' => $a->name,
        'choice' => correctedTo(8), 'note' => 'Neither; it is an 8.',
        'at' => '2026-10-07T12:00:00Z',
    ]));
    expect(storedMark($g))->toMatchArray(['score' => 8, 'last_edited_by' => $a->id]);
});

test('an agreement on a side choice credits that side\'s user', function () {
    [$g, $a, $b] = resolveSetup('-rs-agreed-side');
    $conflict = realConflictBetween($this, $g, $a, $b);
    commandPush($this, $a, $conflict, proposal($a, chooseSide($conflict, 'side_b')));

    commandPush($this, $b, $conflict, agreedResolution($a, $b));

    expect(storedMark($g))->toMatchArray(['score' => 9, 'last_edited_by' => $b->id]);
});

test('a value the cell already holds is not written: no version, no credit change, no mark log row; the conflict is still resolved and logged', function () {
    [$g, $a, $b] = resolveSetup('-rs-equal');
    $moderator = moderatorOf($g, '-rs-equal');
    $conflict = realConflictBetween($this, $g, $a, $b);
    $markLog = markLogRows($g);

    $result = commandPush($this, $moderator, $conflict, moderatedResolution($moderator, correctedTo(6)));

    expect($result['status'])->toBe('accepted');
    expect(storedMark($g))->toMatchArray(['score' => 6, 'last_edited_by' => $a->id, 'version' => 2]);
    expect(markLogRows($g))->toBe($markLog);
    expect(storedConflict($conflict)['resolution']['kind'])->toBe('moderated');
    expect(DB::table('sync_changes')->where('table', 'conflicts')->where('record_id', $conflict->id)->where('version', 2)->count())->toBe(1);
});

test('a corrected absent mark clears the score', function () {
    [$g, $a, $b] = resolveSetup('-rs-absent');
    $moderator = moderatorOf($g, '-rs-absent');
    $conflict = realConflictBetween($this, $g, $a, $b);

    commandPush($this, $moderator, $conflict, moderatedResolution($moderator, ['kind' => 'corrected', 'mark' => ['kind' => 'absent']]));

    expect(storedMark($g))->toMatchArray(['mark_kind' => 'absent', 'score' => null, 'last_edited_by' => $moderator->id]);
});

test('finalize is refused while the conflict is open and succeeds once it is resolved', function () {
    [$g, $a, $b] = resolveSetup('-rs-finalize');
    $moderator = moderatorOf($g, '-rs-finalize');
    $conflict = realConflictBetween($this, $g, $a, $b);

    expect(fn () => $g['assessment']->fresh()->finalize($g['teacher']))->toThrow(ValidationException::class);

    expect(commandPush($this, $moderator, $conflict, moderatedResolution($moderator, chooseSide($conflict)))['status'])->toBe('accepted');

    $g['assessment']->fresh()->finalize($g['teacher']);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('status'))->toBe('finalized');
});

test('a resent resolution replays and writes no second mark, log row, or conflict; an edited note is a different payload', function () {
    [$g, $a, $b] = resolveSetup('-rs-replay');
    $moderator = moderatorOf($g, '-rs-replay');
    $conflict = realConflictBetween($this, $g, $a, $b);
    $id = (string) Str::uuid7();
    $fields = moderatedResolution($moderator, correctedTo(7), 'First wording.');
    commandPush($this, $moderator, $conflict, $fields, id: $id);
    $markLog = markLogRows($g);
    $conflictRows = DB::table('conflicts')->count();

    $again = commandPush($this, $moderator, $conflict, $fields, base: 1, id: $id);
    $edited = commandPush($this, $moderator, $conflict, moderatedResolution($moderator, correctedTo(7), 'Second wording.'), base: 1, id: $id);

    expect($again)->toMatchArray(['status' => 'accepted', 'version' => 2, 'replayed' => true]);
    expect($edited['status'])->toBe('invalid');
    expect(markLogRows($g))->toBe($markLog);
    expect(DB::table('conflicts')->count())->toBe($conflictRows);
    expect(storedConflict($conflict)['version'])->toBe(2);
});

test('a pull after a resolution brings the settled mark and the conflict together', function () {
    [$g, $a, $b] = resolveSetup('-rs-pull');
    $moderator = moderatorOf($g, '-rs-pull');
    $conflict = realConflictBetween($this, $g, $a, $b);
    commandPush($this, $moderator, $conflict, moderatedResolution($moderator, correctedTo(7)));

    app('auth')->forgetGuards();
    $changes = $this->withToken(tokenFor($moderator))->getJson('/api/sync?since=0&limit=500')->assertOk()->json('changes');

    $mark = collect($changes)->where('table', 'marks')->where('recordId', $g['mark']->id)->sortBy('version')->last();
    $settled = collect($changes)->where('table', 'conflicts')->where('recordId', $conflict->id)->sortBy('version')->last();
    expect($mark['fields'])->toMatchArray(['score' => 7]);
    expect($settled['fields'])->toHaveKeys(['resolution', 'resolvedAt']);
    expect($settled['fields']['resolution']['kind'])->toBe('moderated');
});

/* What the resolution is checked against when it is applied, not when it was proposed. */

test('a value that stopped being valid between proposal and acceptance is invalid, and the conflict stays open', function (callable $change) {
    [$g, $a, $b] = resolveSetup('-rs-apply-'.spl_object_id($change));
    $conflict = realConflictBetween($this, $g, $a, $b);
    $moderator = moderatorOf($g, '-rs-apply-'.spl_object_id($change));
    $change($g, $conflict, $a, $b);
    $marksBefore = storedMark($g);
    $versionBefore = storedConflict($conflict)['version'];

    $result = commandPush($this, $moderator, $conflict, moderatedResolution($moderator, chooseSide($conflict, 'side_b')));

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBeString()->not->toBe('');
    expect(storedConflict($conflict)['resolution'])->toBeNull();
    expect(storedConflict($conflict)['version'])->toBe($versionBefore);
    expect(storedMark($g))->toBe($marksBefore);
})->with([
    'the criterion maximum dropped below the chosen side' => [fn ($g) => DB::table('criteria')->where('id', $g['criterion']->id)->update(['max_score' => 8])],
    'the mark was deleted' => [fn ($g) => DB::table('marks')->where('id', $g['mark']->id)->update(['deleted_at' => now()])],
    'the assessment was finalized' => [fn ($g) => DB::table('assessments')->where('id', $g['assessment']->id)->update(['status' => 'finalized'])],
    'the assessment was deleted' => [fn ($g) => DB::table('assessments')->where('id', $g['assessment']->id)->update(['deleted_at' => now()])],
    'a side holds a score with no value' => [function ($g, $c) {
        $side = $c->side_b;
        $side['score'] = null;
        DB::table('conflicts')->where('id', $c->id)->update(['side_b' => json_encode($side)]);
    }],
    'a side holds a kind that is not one' => [function ($g, $c) {
        $side = $c->side_b;
        $side['markKind'] = 'weird';
        DB::table('conflicts')->where('id', $c->id)->update(['side_b' => json_encode($side)]);
    }],
    'the chosen side\'s user is one the scope hides' => [function ($g, $c) {
        app(CurrentInstitution::class)->reset();
        $other = buildGraph('School B', '-rs-apply-hidden');
        $side = $c->side_b;
        $side['userId'] = $other['teacher']->id;
        DB::table('conflicts')->where('id', $c->id)->update(['side_b' => json_encode($side)]);
        app(CurrentInstitution::class)->reset();
    }],
]);

test('an accepted corrected value that exceeds the maximum by the time it is accepted is invalid', function () {
    [$g, $a, $b] = resolveSetup('-rs-apply-max');
    $conflict = realConflictBetween($this, $g, $a, $b);
    commandPush($this, $a, $conflict, proposal($a, correctedTo(9)));
    DB::table('criteria')->where('id', $g['criterion']->id)->update(['max_score' => 8]);

    $result = commandPush($this, $b, $conflict, agreedResolution($a, $b));

    expect($result['status'])->toBe('invalid');
    expect(storedConflict($conflict)['resolution'])->toBeNull();
});

/* The mark moved since the conflict was raised: a stale write, decided by rule 4. */

test('a resolution against a mark that has moved is recorded, leaves the mark, and raises a follow-up between the current value and the chosen one', function () {
    [$g, $a, $b] = resolveSetup('-rs-follow');
    $moderator = moderatorOf($g, '-rs-follow');
    $conflict = realConflictBetween($this, $g, $a, $b);
    $mover = makeColleague($g, 'mover-rs-follow@example.com');
    $moveEntry = pushEntry('marks', $g['mark']->id, 2, ['score' => 7]);
    postedResults(pushEntries($this, tokenFor($mover), [$moveEntry]));
    app('auth')->forgetGuards();
    $markLog = markLogRows($g);
    $id = (string) Str::uuid7();

    $result = commandPush($this, $moderator, $conflict, moderatedResolution($moderator, chooseSide($conflict, 'side_b')), id: $id, at: '2026-10-07T13:00:00Z');

    // Recorded and resolved, whatever happened to the mark.
    expect($result)->toBe(['id' => $id, 'status' => 'accepted', 'version' => 2]);
    expect(storedConflict($conflict)['resolution']['kind'])->toBe('moderated');
    expect(storedConflict($conflict)['resolved_at'])->not->toBeNull();
    expect(storedMark($g))->toMatchArray(['score' => 7, 'last_edited_by' => $mover->id, 'version' => 3]);
    expect(markLogRows($g))->toBe($markLog);

    $followUps = collect(conflictsFor($g['mark']->id))->where('id', '!=', $conflict->id)->values();
    expect($followUps)->toHaveCount(1);
    $follow = $followUps[0];
    expect($follow)->toMatchArray(['base_version' => 2, 'mark_version' => 3, 'resolution' => null]);
    expect($follow['side_a'])->toMatchArray(['editId' => $moveEntry['id'], 'userId' => $mover->id, 'markKind' => 'score', 'score' => 7]);
    expect($follow['side_b'])->toMatchArray(['editId' => $id, 'userId' => $b->id, 'markKind' => 'score', 'score' => 9, 'at' => '2026-10-07T13:00:00Z']);
    expect($follow['side_b']['receivedAt'])->toMatch(COMMAND_STAMP);

    // Its parties are told, and the command's own outcome names no conflict.
    foreach ([$mover, $b] as $party) {
        expect(DB::table('notifications')->where('user_id', $party->id)->where('title', 'Mark conflict to settle')->count())->toBeGreaterThanOrEqual(1);
    }
    expect(DB::table('sync_mutations')->where('id', $id)->value('conflict_id'))->toBeNull();
});

test('a resolution whose value is already in the moved cell writes nothing and raises nothing', function () {
    [$g, $a, $b] = resolveSetup('-rs-follow-equal');
    $moderator = moderatorOf($g, '-rs-follow-equal');
    $conflict = realConflictBetween($this, $g, $a, $b);
    postedResults(pushEntries($this, tokenFor(makeColleague($g, 'mover-rs-follow-equal@example.com')), [pushEntry('marks', $g['mark']->id, 2, ['score' => 7])]));
    app('auth')->forgetGuards();
    $markLog = markLogRows($g);

    $result = commandPush($this, $moderator, $conflict, moderatedResolution($moderator, correctedTo(7)));

    expect($result['status'])->toBe('accepted');
    expect(storedConflict($conflict)['resolution']['kind'])->toBe('moderated');
    expect(markLogRows($g))->toBe($markLog);
    expect(DB::table('conflicts')->where('mark_id', $g['mark']->id)->count())->toBe(1);
});

test('a resolution applies to a mark that moved in a way that does not touch the cell', function () {
    [$g, $a, $b] = resolveSetup('-rs-follow-disjoint');
    $moderator = moderatorOf($g, '-rs-follow-disjoint');
    $conflict = realConflictBetween($this, $g, $a, $b);
    // A write above mark_version that moves nothing the resolution touches: the log row holds only another column.
    DB::table('marks')->where('id', $g['mark']->id)->update(['version' => 3]);
    DB::table('sync_changes')->insert([
        'institution_id' => $g['institution']->id, 'table' => 'marks', 'record_id' => $g['mark']->id,
        'version' => 3, 'fields' => json_encode(['last_edited_by' => $a->id]), 'received_at' => now(),
    ]);

    $result = commandPush($this, $moderator, $conflict, moderatedResolution($moderator, correctedTo(7)));

    expect($result['status'])->toBe('accepted');
    expect(storedMark($g))->toMatchArray(['score' => 7, 'last_edited_by' => $moderator->id, 'version' => 4]);
    expect(DB::table('conflicts')->where('mark_id', $g['mark']->id)->count())->toBe(1);
});

test('a history with a hole fails safe to a follow-up even when the values agree', function () {
    [$g, $a, $b] = resolveSetup('-rs-follow-hole');
    $moderator = moderatorOf($g, '-rs-follow-hole');
    $conflict = realConflictBetween($this, $g, $a, $b);
    DB::table('marks')->where('id', $g['mark']->id)->update(['version' => 3]);

    $result = commandPush($this, $moderator, $conflict, moderatedResolution($moderator, correctedTo(6)));

    expect($result['status'])->toBe('accepted');
    expect(storedMark($g))->toMatchArray(['score' => 6, 'version' => 3]);
    expect(DB::table('conflicts')->where('mark_id', $g['mark']->id)->whereNull('resolution')->count())->toBe(1);
});

test('two conflicts on one cell settle one at a time: the second is a stale write if the first moved the mark', function () {
    [$g, $a, $b] = resolveSetup('-rs-two');
    $moderator = moderatorOf($g, '-rs-two');
    $first = realConflictBetween($this, $g, $a, $b);
    postedResults(pushEntries($this, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => 5])]));
    app('auth')->forgetGuards();
    $second = Conflict::query()->where('mark_id', $g['mark']->id)->orderByDesc('id')->firstOrFail();
    expect($second->id)->not->toBe($first->id);

    // The first moves the mark to 9, b's.
    commandPush($this, $moderator, $first, moderatedResolution($moderator, chooseSide($first, 'side_b')));
    expect(storedMark($g))->toMatchArray(['score' => 9, 'version' => 3]);

    // The second, choosing the value the cell now holds, is the no-op (the case where it chooses another is the derived-side test below).
    expect(commandPush($this, $moderator, $second, moderatedResolution($moderator, correctedTo(9)))['status'])->toBe('accepted');
    expect(DB::table('conflicts')->where('mark_id', $g['mark']->id)->count())->toBe(2);
});

test('a conflict that did not move the mark leaves the next resolution to apply directly', function () {
    [$g, $a, $b] = resolveSetup('-rs-two-direct');
    $moderator = moderatorOf($g, '-rs-two-direct');
    $first = realConflictBetween($this, $g, $a, $b);
    postedResults(pushEntries($this, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => 5])]));
    app('auth')->forgetGuards();
    $second = Conflict::query()->where('mark_id', $g['mark']->id)->orderByDesc('id')->firstOrFail();

    // The first chooses the value the cell already holds (a's 6): nothing moves.
    commandPush($this, $moderator, $first, moderatedResolution($moderator, chooseSide($first, 'side_a')));
    expect(storedMark($g)['version'])->toBe(2);

    // So the second applies directly.
    commandPush($this, $moderator, $second, moderatedResolution($moderator, chooseSide($second, 'side_b')));
    expect(storedMark($g))->toMatchArray(['score' => 5, 'version' => 3, 'last_edited_by' => $b->id]);
    expect(DB::table('conflicts')->where('mark_id', $g['mark']->id)->count())->toBe(2);
});

test('a follow-up whose side A is an earlier resolution has a derived editId, the credited user, and no at', function () {
    [$g, $a, $b] = resolveSetup('-rs-derived');
    $moderator = moderatorOf($g, '-rs-derived');
    $first = realConflictBetween($this, $g, $a, $b);
    postedResults(pushEntries($this, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => 5])]));
    app('auth')->forgetGuards();
    $second = Conflict::query()->where('mark_id', $g['mark']->id)->orderByDesc('id')->firstOrFail();
    commandPush($this, $moderator, $first, moderatedResolution($moderator, chooseSide($first, 'side_b')));

    commandPush($this, $moderator, $second, moderatedResolution($moderator, chooseSide($second, 'side_b')));

    $follow = collect(conflictsFor($g['mark']->id))->whereNotIn('id', [$first->id, $second->id])->values();
    expect($follow)->toHaveCount(1);
    expect($follow[0]['side_a'])->toMatchArray([
        'editId' => Uuid::uuid5(MarkConflicts::EDIT_ID_NAMESPACE, "marks:{$g['mark']->id}:3")->toString(),
        'userId' => $b->id,
        'at' => null,
        'score' => 9,
    ]);
});

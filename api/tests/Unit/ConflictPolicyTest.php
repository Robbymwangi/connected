<?php

use App\Sync\Push\CommandKind;
use App\Sync\Push\ConflictPolicy;
use App\Sync\Push\ConflictRole;
use App\Sync\Push\ConflictState;
use App\Sync\Push\SyncRejection;

/* Who may do what to a mark conflict, decided with no database (docs/spec/sync-protocol.md,
   "Conflict commands"; ADR 0002 and its 2026-10-07 amendment). The cases are one shared file, also read by the
   client's test, so the two policies cannot drift. The server runs the actor rules before the version check and
   the state rules after it; the table is their composition. */

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function policyCases(): array
{
    $file = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/conflict-policy.json'), true, flags: JSON_THROW_ON_ERROR);

    return collect($file['cases'])->mapWithKeys(fn (array $case) => [$case['name'] => [$case]])->all();
}

/** The label of a user in a case: a and b are teachers, m moderates and is not a party, p is a party who moderates. */
function policyVerdict(array $case): string
{
    $actor = $case['actor'];
    $moderates = in_array($actor, ['m', 'p'], true);
    $role = ConflictPolicy::role($actor, $case['sides'][0], $case['sides'][1], $moderates);
    $kind = CommandKind::from($case['act']);
    $state = new ConflictState($case['proposals'], $case['referred'], $case['resolved']);

    $rejection = ConflictPolicy::actor($role, $kind) ?? ConflictPolicy::state($role, $kind, $state, $actor);

    return $rejection === null ? 'allowed' : $rejection->status;
}

test('the policy table', function (array $case) {
    expect(policyVerdict($case))->toBe($case['expected']);
})->with(fn () => policyCases());

test('a party who also moderates the subject is a party, never a moderator', function () {
    expect(ConflictPolicy::role('a', 'a', 'b', true))->toBe(ConflictRole::Party)
        ->and(ConflictPolicy::role('a', 'a', 'a', true))->toBe(ConflictRole::SelfAuthor);
});

test('roles', function () {
    expect(ConflictPolicy::role('a', 'a', 'b', false))->toBe(ConflictRole::Party)
        ->and(ConflictPolicy::role('b', 'a', 'b', false))->toBe(ConflictRole::Party)
        ->and(ConflictPolicy::role('a', 'a', 'a', false))->toBe(ConflictRole::SelfAuthor)
        ->and(ConflictPolicy::role('m', 'a', 'b', true))->toBe(ConflictRole::Moderator)
        ->and(ConflictPolicy::role('m', 'a', 'a', true))->toBe(ConflictRole::ModeratorOfSelfConflict)
        ->and(ConflictPolicy::role('x', 'a', 'b', false))->toBe(ConflictRole::None);
});

test('a referral is for rounds after two proposals and for a party before', function () {
    expect(ConflictPolicy::referralReason(0))->toBe('party')
        ->and(ConflictPolicy::referralReason(1))->toBe('party')
        ->and(ConflictPolicy::referralReason(2))->toBe('rounds')
        ->and(ConflictPolicy::referralReason(3))->toBe('rounds');
});

test('each rejection carries its status and a reason the device can show', function () {
    $forbidden = ConflictPolicy::actor(ConflictRole::None, CommandKind::Propose);
    $invalid = ConflictPolicy::state(ConflictRole::Party, CommandKind::Accept, new ConflictState([], false, false), 'a');

    expect($forbidden->status)->toBe('forbidden')
        ->and($invalid->status)->toBe('invalid')
        ->and($invalid->reasonText)->toBeString()->not->toBe('');
});

/* The wire kind of a resolution (3.2c, G): agreed is an acceptance, self and moderated are direct resolutions, and
   the kind must fit who is resolving. These are server-only cases, so they stay out of the case file the client reads. */

test('the wire kinds map to commands, and auto and anything unknown are refused', function () {
    expect(ConflictPolicy::commandFor('agreed'))->toBe(CommandKind::Accept)
        ->and(ConflictPolicy::commandFor('self'))->toBe(CommandKind::Resolve)
        ->and(ConflictPolicy::commandFor('moderated'))->toBe(CommandKind::Resolve);

    foreach (['auto', 'forced', '', 7, null, ['agreed']] as $kind) {
        $refused = ConflictPolicy::commandFor($kind);

        expect($refused)->toBeInstanceOf(SyncRejection::class)->and($refused->status)->toBe('invalid');
    }
    expect(ConflictPolicy::commandFor('auto')->reasonText)->toBe('auto is written only by the server');
});

test('a self resolution fits only the author of a self-conflict, and a moderated one only a moderator', function () {
    expect(ConflictPolicy::resolutionFits(ConflictRole::SelfAuthor, 'self'))->toBeNull()
        ->and(ConflictPolicy::resolutionFits(ConflictRole::Moderator, 'moderated'))->toBeNull()
        ->and(ConflictPolicy::resolutionFits(ConflictRole::Party, 'agreed'))->toBeNull()
        ->and(ConflictPolicy::resolutionFits(ConflictRole::Moderator, 'self')->status)->toBe('invalid')
        ->and(ConflictPolicy::resolutionFits(ConflictRole::SelfAuthor, 'moderated')->status)->toBe('invalid');
});

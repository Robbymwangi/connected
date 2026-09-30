<?php

use Laravel\Sanctum\PersonalAccessToken;

/* Ticket #43 (docs/build-plan.md 1.5): Sanctum bearer tokens, one per
   device, per docs/spec/access-model.md, Tokens. Login has no account yet,
   so it is the one route that reads across institutions on purpose
   (LoginController's withoutGlobalScopes()); logout and /me sit behind
   auth:sanctum and EnsureAccountIsActive like every other protected route. */

test('logging in with the right email and password returns a token that authenticates a protected route', function () {
    $g = buildGraph('School A', '-login-ok');

    $token = $this->postJson('/api/login', [
        'email' => 'teacher-login-ok@example.com',
        'password' => 'a-hashed-password',
    ])->assertOk()->json('token');

    expect($token)->toBeString();

    // The sanctum guard built for the login request above cached the
    // unauthenticated result against that request; forget it so this call
    // is resolved against its own token, matching InstitutionScopeTest's
    // pattern for consecutive differently-authenticated requests.
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertJson(['id' => $g['teacher']->id]);
});

test('a wrong password is rejected with the same message as an unknown email', function () {
    buildGraph('School A', '-login-wrong');

    $wrongPassword = $this->postJson('/api/login', [
        'email' => 'teacher-login-wrong@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422)->json('errors.email.0');

    $unknownEmail = $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever',
    ])->assertStatus(422)->json('errors.email.0');

    expect($wrongPassword)->toBe($unknownEmail);
});

test('login is rate limited to five attempts a minute, keyed by email and IP together', function () {
    buildGraph('School A', '-login-throttle');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', [
            'email' => 'teacher-login-throttle@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    $this->postJson('/api/login', [
        'email' => 'teacher-login-throttle@example.com',
        'password' => 'a-hashed-password',
    ])->assertStatus(429);

    // A different email from the same request, immediately after, is not
    // caught by the same key.
    $this->postJson('/api/login', [
        'email' => 'someone-else-login-throttle@example.com',
        'password' => 'wrong',
    ])->assertStatus(422);
});

test('login also caps one IP at twenty attempts a minute, regardless of which email it tries', function () {
    // Five attempts each against four different, nonexistent emails: none
    // of them hits the five-a-minute per-email limit, so only the IP-wide
    // limit can be the one that trips.
    for ($email = 0; $email < 4; $email++) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => "nobody-ip-cap-{$email}@example.com",
                'password' => 'wrong',
            ])->assertStatus(422);
        }
    }

    $this->postJson('/api/login', [
        'email' => 'nobody-ip-cap-4@example.com',
        'password' => 'wrong',
    ])->assertStatus(429);
});

test('a deactivated account is rejected the same way, even with the correct password', function () {
    $g = buildGraph('School A', '-login-deactivated');
    $g['teacher']->update(['deactivated_at' => now()]);

    $this->postJson('/api/login', [
        'email' => 'teacher-login-deactivated@example.com',
        'password' => 'a-hashed-password',
    ])->assertStatus(422);
});

test('login is not scoped to any one institution, since no account is authenticated yet', function () {
    buildGraph('School A', '-login-cross-a');
    buildGraph('School B', '-login-cross-b');

    $this->postJson('/api/login', [
        'email' => 'teacher-login-cross-b@example.com',
        'password' => 'a-hashed-password',
    ])->assertOk();
});

test('logout revokes the token that authenticated the request, and only that one', function () {
    $g = buildGraph('School A', '-logout');
    $kept = $g['teacher']->createToken('kept-device', ['sync'])->plainTextToken;
    $revoked = $g['teacher']->createToken('revoked-device', ['sync'])->plainTextToken;

    $this->withToken($revoked)->postJson('/api/logout')->assertOk();

    expect(PersonalAccessToken::findToken($revoked))->toBeNull();
    expect(PersonalAccessToken::findToken($kept))->not->toBeNull();

    app('auth')->forgetGuards();
    $this->withToken($revoked)->getJson('/api/me')->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->withToken($kept)->getJson('/api/me')->assertOk();
});

test('a token issued before deactivation stops working after it, not only a new login', function () {
    $g = buildGraph('School A', '-deactivated-token');
    $token = $g['teacher']->createToken('device', ['sync'])->plainTextToken;

    $this->withToken($token)->getJson('/api/me')->assertOk();

    $g['teacher']->update(['deactivated_at' => now()]);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/logout')->assertUnauthorized();
});

test('logout and me are refused without a token', function () {
    $this->postJson('/api/logout')->assertUnauthorized();
    $this->getJson('/api/me')->assertUnauthorized();
});

test('me returns the account, its admin flag, and the subjects it moderates, not a flattened boolean', function () {
    $g = buildGraph('School A', '-me');
    $token = $g['teacher']->createToken('device', ['sync'])->plainTextToken;

    $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertExactJson([
            'id' => $g['teacher']->id,
            'name' => $g['teacher']->name,
            'email' => 'teacher-me@example.com',
            'is_admin' => false,
            'moderated_subject_ids' => [$g['subject']->id],
        ]);
});

test('me is scoped to the account\'s own institution: another institution\'s moderation grant never appears', function () {
    $a = buildGraph('School A', '-me-scope-a');
    $b = buildGraph('School B', '-me-scope-b');

    // Same teacher name and moderated subject in both schools by construction;
    // the point is that A's token only ever sees A's subject id.
    $token = $a['teacher']->createToken('device', ['sync'])->plainTextToken;

    $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertJson(['moderated_subject_ids' => [$a['subject']->id]]);

    expect($a['subject']->id)->not->toBe($b['subject']->id);
});

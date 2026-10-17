<?php

use App\Models\Institution;
use App\Models\SyncChange;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/* Build plan 5.2, what the application needs before it is deployed: the one-time command that
   creates a real school and its first administrator, admin MFA in the panel, and the client's
   address and scheme read correctly from behind CloudFront. */

function provision(mixed $test, array $answers, array $options = [])
{
    $command = $test->artisan('connected:provision-school', $options);
    foreach (['School name', 'Administrator\'s full name', 'Administrator\'s email'] as $question) {
        if (array_key_exists($question, $answers)) {
            $command->expectsQuestion($question, $answers[$question]);
        }
    }

    return $command
        ->expectsQuestion('Administrator\'s password (at least 12 characters)', $answers['password'])
        ->expectsQuestion('The password again', $answers['confirm'] ?? $answers['password']);
}

test('provisioning creates a school and its first administrator, who can sign in to the panel', function () {
    provision($this, [
        'School name' => 'Kilimani Junior School', 'Administrator\'s full name' => 'Wanjiku Kamau',
        'Administrator\'s email' => 'admin@kilimani.test', 'password' => 'a-strong-passphrase',
    ])->assertSuccessful();

    $school = Institution::query()->where('name', 'Kilimani Junior School')->sole();
    $admin = User::withoutGlobalScopes()->where('email', 'admin@kilimani.test')->sole();
    expect($admin->institution_id)->toBe($school->id)
        ->and($admin->kind)->toBe(User::ADMIN)
        ->and(Hash::check('a-strong-passphrase', $admin->password))->toBeTrue()
        ->and($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

test('provisioning refuses a short or mismatched password, a taken admin email, or a school that exists, and creates nothing', function (array $answers) {
    $g = buildGraph('Existing School', '-provision');
    makeColleague($g, 'taken@example.com', admin: true);
    $schools = Institution::query()->count();

    provision($this, [
        'School name' => 'New School', 'Administrator\'s full name' => 'A. Admin',
        'Administrator\'s email' => 'new@example.com', 'password' => 'a-strong-passphrase', ...$answers,
    ])->assertFailed();

    expect(Institution::query()->count())->toBe($schools);
    expect(User::withoutGlobalScopes()->where('email', 'new@example.com')->exists())->toBeFalse();
})->with([
    'a short password' => [['password' => 'too-short']],
    'a mismatched confirmation' => [['confirm' => 'a-different-passphrase']],
    'an admin email in use' => [['Administrator\'s email' => 'taken@example.com']],
    'a school that exists' => [['School name' => 'Existing School']],
]);

test('outside production the panel does not require an authenticator app, so the development admin signs in with a password', function () {
    $g = buildGraph();
    $admin = makeColleague($g, 'dev@example.com', admin: true);

    expect(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired())->toBeFalse();
    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
});

test('an admin\'s MFA secret and recovery codes are stored encrypted and never enter the sync log', function () {
    $g = buildGraph();
    $admin = makeColleague($g, 'mfa@example.com', admin: true);

    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $admin->saveAppAuthenticationRecoveryCodes(['code-one', 'code-two']);

    $stored = DB::table('users')->where('id', $admin->id)->first();
    expect($stored->app_authentication_secret)->not->toContain('JBSWY3DPEHPK3PXP');
    expect($admin->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP');
    $logged = SyncChange::withoutGlobalScopes()->where('record_id', $admin->id)->pluck('fields')->map(fn ($fields) => json_encode($fields))->implode(' ');
    expect($logged)->not->toContain('app_authentication')->not->toContain('JBSWY3DPEHPK3PXP');
});

test('behind CloudFront the request knows the client\'s address and that it arrived over https', function () {
    Route::get('/__proxy-probe', fn (Request $request) => ['secure' => $request->isSecure(), 'ip' => $request->ip()]);

    $this->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '196.201.214.10'])
        ->assertOk()
        ->assertExactJson(['secure' => true, 'ip' => '196.201.214.10']);
});

test('in production an admin without an authenticator app is sent to set one up before the panel opens', function () {
    // Filament attaches the requirement to the panel's routes as the application boots, so the
    // application is booted again as production, as the host boots it.
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
    putenv('APP_ENV=production');
    try {
        $this->refreshApplication();
        $this->setUpTraits();
        $g = buildGraph();
        $admin = makeColleague($g, 'setup@example.com', admin: true);

        $this->actingAs($admin, 'admin')->get('/admin')->assertRedirectContains('multi-factor-authentication');
    } finally {
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');
    }
});
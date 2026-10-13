<?php

use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* ADR 0014, decision 1, at the API's door: an admin account never signs in to the app, and the
   migration that introduced the two kinds. The panel's door is in AdminPanelTest. */

const SEPARATE_ACCOUNTS_MIGRATION = 'database/migrations/2026_10_13_000001_separate_admin_and_teacher_accounts.php';

test('an admin account is refused at the API login with the same message as a wrong password', function () {
    $g = buildGraph('School A', '-door-admin');
    makeColleague($g, 'office-door-admin@example.com', admin: true);

    $admin = $this->postJson('/api/login', ['email' => 'office-door-admin@example.com', 'password' => 'a-hashed-password'])
        ->assertStatus(422)->json('errors.email.0');
    $wrong = $this->postJson('/api/login', ['email' => 'teacher-door-admin@example.com', 'password' => 'not-the-password'])
        ->assertStatus(422)->json('errors.email.0');

    expect($admin)->toBe($wrong);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('one address may hold a teacher and an admin account, and the API signs in only the teacher', function () {
    $g = buildGraph('School A', '-door-shared');
    $teacher = makeColleague($g, 'head-door-shared@example.com');
    $teacher->update(['password' => 'the-teaching-password']);
    $admin = makeColleague($g, 'head-door-shared@example.com', admin: true);
    $admin->update(['password' => 'the-office-password']);

    $this->postJson('/api/login', ['email' => 'head-door-shared@example.com', 'password' => 'the-office-password'])->assertStatus(422);
    $token = $this->postJson('/api/login', ['email' => 'head-door-shared@example.com', 'password' => 'the-teaching-password'])
        ->assertOk()->json('token');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('id', $teacher->id);
});

test('a token held by an admin account is refused, however it was issued', function () {
    $g = buildGraph('School A', '-door-token');
    $admin = makeColleague($g, 'office-door-token@example.com', admin: true);

    $this->withToken(tokenFor($admin))->getJson('/api/me')->assertUnauthorized();
});

test('the Director of Studies grant is held by teacher accounts only', function () {
    $g = buildGraph('School A', '-dos-kind');

    expect(fn () => makeColleague($g, 'office-dos-kind@example.com', admin: true, directsStudies: true))
        ->toThrow(QueryException::class, 'users_directs_studies_teacher_check');
});

test('the migration makes each is_admin account an admin in place, revoking its device tokens', function () {
    $g = buildGraph('School A', '-mig-ok');
    $office = makeColleague($g, 'office-mig-ok@example.com');
    tokenFor($office);
    tokenFor($g['teacher']);

    Artisan::call('migrate:rollback', ['--path' => SEPARATE_ACCOUNTS_MIGRATION, '--force' => true]);
    expect(Schema::hasColumn('users', 'kind'))->toBeFalse();
    DB::table('users')->where('id', $office->id)->update(['is_admin' => true]);
    Artisan::call('migrate', ['--path' => SEPARATE_ACCOUNTS_MIGRATION, '--force' => true]);

    expect(DB::table('users')->where('id', $office->id)->value('kind'))->toBe(User::ADMIN);
    expect(DB::table('users')->where('id', $g['teacher']->id)->value('kind'))->toBe(User::TEACHER);
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $office->id)->exists())->toBeFalse();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $g['teacher']->id)->exists())->toBeTrue();
});

test('the migration refuses an is_admin account that also teaches, rather than choose a side for it', function () {
    $g = buildGraph('School A', '-mig-refuse');
    $head = makeColleague($g, 'head-mig-refuse@example.com');
    TeacherAssignment::create([
        'institution_id' => $g['institution']->id, 'user_id' => $head->id,
        'class_id' => $g['class']->id, 'subject_id' => $g['subject']->id,
    ]);

    Artisan::call('migrate:rollback', ['--path' => SEPARATE_ACCOUNTS_MIGRATION, '--force' => true]);
    DB::table('users')->where('id', $head->id)->update(['is_admin' => true]);

    expect(fn () => Artisan::call('migrate', ['--path' => SEPARATE_ACCOUNTS_MIGRATION, '--force' => true]))
        ->toThrow(RuntimeException::class, $head->id);
    expect(Schema::hasColumn('users', 'is_admin'))->toBeTrue();
});

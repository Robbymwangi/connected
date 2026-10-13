<?php

use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Resources\Assessments\Pages\ListAssessments;
use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Teachers\Pages\EditUser;
use App\Filament\Resources\Teachers\Pages\ListUsers;
use App\Filament\Resources\Teachers\RelationManagers\SubjectModerationsRelationManager;
use App\Http\Middleware\ResolveInstitution;
use App\Models\Student;
use App\Models\SubjectModeration;
use App\Models\User;
use App\Support\CurrentInstitution;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

/* Build plan 5.4 to 5.6, the admin panel (ADR 0009), each test one of the plan's "done when" lines.
   Page loads go through HTTP, so the panel's own middleware, ResolveInstitution with the admin
   guard included, runs as it does for a real browser. Livewire component tests run no middleware,
   so where one needs the institution the test begins the request as that middleware would. */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function adminOf(array $g, string $email = 'admin@example.com'): User
{
    return makeColleague($g, $email, admin: true);
}

/** What ResolveInstitution does at the start of a panel request, for a Livewire test. */
function asPanelRequestFor(?User $user): void
{
    app(CurrentInstitution::class)->reset();
    app(CurrentInstitution::class)->beginRequest($user?->institution_id);
}

test('an administrator signs in at the panel although the institution is unknown until they do', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $admin->update(['password' => 'a-long-enough-password']);
    asPanelRequestFor(null);

    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'a-long-enough-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('admin')->id())->toBe($admin->id);
});

test('a non-admin, a deactivated admin, and a wrong password fail the same way', function () {
    $g = buildGraph();
    $g['teacher']->update(['password' => 'a-long-enough-password']);
    $gone = adminOf($g, 'gone@example.com');
    $gone->update(['password' => 'a-long-enough-password', 'deactivated_at' => now()]);
    $admin = adminOf($g);
    asPanelRequestFor(null);

    $messages = collect([
        [$g['teacher']->email, 'a-long-enough-password'],
        [$gone->email, 'a-long-enough-password'],
        [$admin->email, 'not-the-password'],
    ])->map(function (array $credentials) {
        $this->app['auth']->forgetGuards();

        return Livewire::test(Login::class)
            ->fillForm(['email' => $credentials[0], 'password' => $credentials[1]])
            ->call('authenticate')
            ->assertHasFormErrors(['email'])
            ->errors()->first('data.email');
    });

    expect($messages->unique())->toHaveCount(1);
    expect(auth('admin')->check())->toBeFalse();
});

test('the panel login is rate limited, apart from the API login\'s own limit', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $admin->update(['password' => 'a-long-enough-password']);
    asPanelRequestFor(null);

    foreach (range(1, 5) as $ignored) {
        Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'wrong-password'])->call('authenticate');
    }
    Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'a-long-enough-password'])->call('authenticate')->assertNotified();

    expect(auth('admin')->check())->toBeFalse();
});

test('lists and searches show only the administrator\'s own institution', function () {
    $g = buildGraph('Hillside Academy', '-mine');
    $other = buildGraph('Other School', '-theirs');
    Student::withoutGlobalScopes()->whereKey($other['student']->id)->update(['name' => 'Zawadi Theirs']);
    Student::withoutGlobalScopes()->whereKey($g['student']->id)->update(['name' => 'Wanjiku Mine']);
    $admin = adminOf($g);

    $this->actingAs($admin, 'admin')->get('/admin/students')->assertOk()->assertSee('Wanjiku Mine')->assertDontSee('Zawadi Theirs');
    $this->actingAs($admin, 'admin')->get('/admin/students?tableSearch=Zawadi')->assertOk()->assertDontSee('Zawadi Theirs');
    $this->actingAs($admin, 'admin')->get('/admin/teachers')->assertOk()->assertDontSee($other['teacher']->email);
});

test('every Livewire request of the panel is scoped too, not only the first page load', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(ResolveInstitution::class.':admin');
});

test('another institution\'s record is a 404 by its id', function () {
    $g = buildGraph('Hillside Academy', '-mine');
    $other = buildGraph('Other School', '-theirs');

    $admin = adminOf($g);

    $this->actingAs($admin, 'admin')->get("/admin/students/{$other['student']->id}/edit")->assertNotFound();
    $this->actingAs($admin, 'admin')->get("/admin/teachers/{$other['teacher']->id}/edit")->assertNotFound();
    $this->actingAs($admin, 'admin')->get("/admin/students/{$g['student']->id}/edit")->assertOk();
});

test('a record created in the panel belongs to the administrator\'s institution without the form sending it', function () {
    $g = buildGraph('Hillside Academy', '-mine');
    buildGraph('Other School', '-theirs');
    $admin = adminOf($g);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(CreateStudent::class)
        ->fillForm(['name' => 'New Pupil', 'gender' => 'F', 'dob' => '2016-03-01'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Student::withoutGlobalScopes()->where('name', 'New Pupil')->sole()->institution_id)->toBe($g['institution']->id);
});

test('a panel session is not an API credential', function () {
    $g = buildGraph();

    $this->actingAs(adminOf($g), 'admin')->getJson('/api/me')->assertUnauthorized();
});

test('a non-admin cannot reach the panel, and a deactivated admin is refused on the next request', function () {
    $g = buildGraph();
    $admin = adminOf($g);

    $this->actingAs($g['teacher'], 'admin')->get('/admin')->assertForbidden();
    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
    $admin->update(['deactivated_at' => now()]);
    $this->actingAs($admin->fresh(), 'admin')->get('/admin')->assertForbidden();
});

test('deactivating an account revokes its tokens in the same request', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $token = tokenFor($g['teacher']);
    $this->withToken($token)->getJson('/api/me')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(ListUsers::class)->callTableAction('deactivate', $g['teacher']);

    expect($g['teacher']->fresh()->deactivated_at)->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
});

test('an administrator cannot deactivate themselves, nor the last active administrator', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(ListAdmins::class)->callTableAction('deactivate', $admin)->assertNotified('You cannot deactivate your own account.');
    expect($admin->fresh()->deactivated_at)->toBeNull();

    $second = adminOf($g, 'second@example.com');
    Livewire::test(ListAdmins::class)->callTableAction('deactivate', $second)->assertNotified('Account deactivated');
    $second->refresh()->update(['deactivated_at' => null]);
    $admin->update(['deactivated_at' => now()]);
    expect(fn () => $second->deactivate($admin))->toThrow(DomainException::class, 'only active administrator');
    expect($second->fresh()->deactivated_at)->toBeNull();
});

test('the account pages offer no delete, and revoking devices signs a teacher out without deactivating them', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $token = tokenFor($g['teacher']);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(EditUser::class, ['record' => $g['teacher']->getRouteKey()])->assertActionDoesNotExist('delete');
    Livewire::test(ListUsers::class)->assertTableActionDoesNotExist('delete')->callTableAction('revokeDevices', $g['teacher']);

    expect($g['teacher']->fresh()->deactivated_at)->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
});

test('a head teacher\'s two accounts on one address: the panel signs in only the admin', function () {
    $g = buildGraph();
    $teacher = makeColleague($g, 'head@example.com');
    $teacher->update(['password' => 'the-teaching-password']);
    $admin = adminOf($g, 'head@example.com');
    $admin->update(['password' => 'the-office-password']);
    asPanelRequestFor(null);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'head@example.com', 'password' => 'the-teaching-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
    expect(auth('admin')->check())->toBeFalse();

    $this->app['auth']->forgetGuards();
    Livewire::test(Login::class)
        ->fillForm(['email' => 'head@example.com', 'password' => 'the-office-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
    expect(auth('admin')->id())->toBe($admin->id);
});

test('the Teachers screen lists teacher accounts and the Admins screen admin accounts, neither the other', function () {
    $g = buildGraph();
    $admin = adminOf($g);

    $this->actingAs($admin, 'admin')->get('/admin/teachers')->assertOk()->assertSee($g['teacher']->email)->assertDontSee($admin->email);
    $this->actingAs($admin, 'admin')->get('/admin/admins')->assertOk()->assertSee($admin->email)->assertDontSee($g['teacher']->email);
    $this->actingAs($admin, 'admin')->get("/admin/teachers/{$admin->id}/edit")->assertNotFound();
});

test('an admin made in the panel is an admin account, and its address need be unique only among admins', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(CreateAdmin::class)
        ->fillForm(['name' => 'Head Teacher', 'email' => $g['teacher']->email, 'password' => 'a-long-enough-password'])
        ->call('create')
        ->assertHasNoFormErrors();
    Livewire::test(CreateAdmin::class)
        ->fillForm(['name' => 'Someone Else', 'email' => $admin->email, 'password' => 'a-long-enough-password'])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);

    $made = User::query()->where('name', 'Head Teacher')->sole();
    expect($made->kind)->toBe(User::ADMIN)->and($made->institution_id)->toBe($g['institution']->id);
});

test('the Director of Studies grant is made and revoked on a teacher account', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(EditUser::class, ['record' => $g['teacher']->getRouteKey()])
        ->fillForm(['directs_studies' => true])->call('save')->assertHasNoFormErrors();
    expect($g['teacher']->fresh()->directsStudies())->toBeTrue();

    Livewire::test(EditUser::class, ['record' => $g['teacher']->getRouteKey()])
        ->fillForm(['directs_studies' => false])->call('save')->assertHasNoFormErrors();
    expect($g['teacher']->fresh()->directsStudies())->toBeFalse();
});

test('a moderation grant is made and revoked on the account', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);
    $g['subjectModeration']->delete();

    Livewire::test(SubjectModerationsRelationManager::class, ['ownerRecord' => $g['teacher'], 'pageClass' => EditUser::class])
        ->callTableAction('create', data: ['subject_id' => $g['subject']->id])
        ->assertHasNoTableActionErrors();

    expect(SubjectModeration::query()->where('user_id', $g['teacher']->id)->where('subject_id', $g['subject']->id)->count())->toBe(1);
});

test('unlocking through the panel writes the same unlock note the model rule writes for the API', function () {
    $graph = buildGraph();
    $admin = adminOf($graph);
    $graph['assessment']->update(['status' => 'finalized', 'finalized_at' => '2026-10-05 10:00:00', 'finalized_by' => $graph['teacher']->id]);
    $closure = closeGraphTerm($graph);
    issuedReport($graph);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(ListAssessments::class)
        ->callTableAction('unlock', $graph['assessment'], data: ['note' => '  Correct the recorded score.  '])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('unlock_notes', [
        'assessment_id' => $graph['assessment']->id,
        'institution_id' => $graph['institution']->id,
        'user_id' => $admin->id,
        'note' => 'Correct the recorded score.',
    ]);
    $this->assertDatabaseCount('unlock_notes', 1);
    $this->assertSoftDeleted($closure);
    expect($graph['assessment']->fresh()->status)->toBe('scheduled');
});

test('with reports issued, the panel requires the note', function () {
    $graph = buildGraph();
    $admin = adminOf($graph);
    $graph['assessment']->update(['status' => 'finalized', 'finalized_at' => '2026-10-05 10:00:00', 'finalized_by' => $graph['teacher']->id]);
    closeGraphTerm($graph);
    issuedReport($graph);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(ListAssessments::class)
        ->callTableAction('unlock', $graph['assessment'], data: ['note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);

    expect($graph['assessment']->fresh()->status)->toBe('finalized');
});

test('the finalized filter lists a row still holding the retired reports-generated status', function () {
    $graph = buildGraph();
    $admin = adminOf($graph);
    $graph['assessment']->update(['status' => 'reports-generated', 'finalized_at' => '2026-10-05 10:00:00', 'finalized_by' => $graph['teacher']->id]);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(ListAssessments::class)
        ->filterTable('status', 'finalized')->assertCanSeeTableRecords([$graph['assessment']])
        ->filterTable('status', 'scheduled')->assertCanNotSeeTableRecords([$graph['assessment']]);
});

arch('no API controller depends on the admin panel')
    ->expect('App\Http\Controllers')
    ->not->toUse('Filament');

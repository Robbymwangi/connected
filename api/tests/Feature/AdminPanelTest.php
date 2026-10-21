<?php

use App\Filament\Auth\Login;
use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Resources\Classes\Pages\CreateSchoolClass;
use App\Filament\Resources\Classes\Pages\EditSchoolClass;
use App\Filament\Resources\Classes\RelationManagers\ClassSubjectsRelationManager;
use App\Filament\Resources\Classes\RelationManagers\EnrolmentsRelationManager;
use App\Filament\Resources\Classes\RelationManagers\TeacherAssignmentsRelationManager;
use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Subjects\Pages\CreateSubject;
use App\Filament\Resources\Subjects\Pages\EditSubject;
use App\Filament\Resources\Subjects\RelationManagers\CriteriaRelationManager;
use App\Filament\Resources\Teachers\Pages\EditUser;
use App\Filament\Resources\Teachers\Pages\ListUsers;
use App\Filament\Resources\Teachers\RelationManagers\SubjectModerationsRelationManager;
use App\Http\Middleware\ResolveAdminInstitution;
use App\Http\Middleware\ResolveInstitution;
use App\Models\ClassSubject;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectModeration;
use App\Models\SyncChange;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\CurrentInstitution;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

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

test('signing in never writes a remember token, so a login does not touch the synchronisable users row', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $admin->update(['password' => 'a-long-enough-password']);
    $versionBefore = $admin->fresh()->version;
    asPanelRequestFor(null);

    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'a-long-enough-password', 'remember' => true])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('admin')->id())->toBe($admin->id)
        ->and($admin->fresh()->version)->toBe($versionBefore);
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
    /* A Livewire update (a search, a save, a modal's create) re-runs only those of its page's route
       middleware that Livewire matches against its persistent list, comparing names with any
       ":argument" stripped from the route's side alone. ResolveInstitution:admin never matched, so
       updates ran with no institution: unscoped reads, and a create with a null institution_id.
       Being in the persistent list is not enough; this runs Livewire's own match on every panel page. */
    $persistent = app(PersistentMiddleware::class);
    $reapplied = new ReflectionMethod($persistent, 'filterMiddlewareByPersistentMiddleware');
    $pages = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'filament.admin.') && in_array('GET', $route->methods(), true));

    expect($pages)->not->toBeEmpty();
    foreach ($pages as $page) {
        expect($reapplied->invoke($persistent, app('router')->gatherRouteMiddleware($page)))
            ->toContain(ResolveAdminInstitution::class);
    }
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

test('the panel does no academic work: it has no assessments screen, and an admin cannot unlock', function () {
    $graph = buildGraph();
    $admin = adminOf($graph);
    $graph['assessment']->update(['status' => 'finalized', 'finalized_at' => '2026-10-05 10:00:00', 'finalized_by' => $graph['teacher']->id]);

    $this->actingAs($admin, 'admin')->get('/admin/assessments')->assertNotFound();
    expect(Gate::forUser($admin)->allows('unlock', $graph['assessment']))->toBeFalse();
});

/* Setting up a school from nothing (the panel is a real school's only way in; the demo seeder never
   runs in production): subjects and their rubrics, what each class offers, its students for the
   year, and who teaches what. Every row is the synchronisable model, so it carries a version and
   a sync_changes entry, which is how a device learns of it. */

/** A Livewire test of a relation manager on one of the administrator's own records. */
function relationTest(string $manager, $owner, string $page, User $admin)
{
    test()->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    return Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => $page]);
}

function newPupil(array $g, string $name): Student
{
    return Student::create(['institution_id' => $g['institution']->id, 'name' => $name, 'gender' => 'M', 'dob' => '2015-06-01']);
}

test('a subject is made in the panel, once by name, and lands in the sync log', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(CreateSubject::class)->fillForm(['name' => 'Kiswahili'])->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateSubject::class)->fillForm(['name' => 'Maths'])->call('create')->assertHasFormErrors(['name' => 'unique']);

    $subject = Subject::query()->where('name', 'Kiswahili')->sole();
    expect($subject->institution_id)->toBe($g['institution']->id)
        ->and($subject->version)->toBe(1)
        ->and(SyncChange::query()->where('table', 'subjects')->where('record_id', $subject->id)->exists())->toBeTrue();
});

test('another school may have a subject of the same name', function () {
    buildGraph('Other School', '-theirs');
    $g = buildGraph('Hillside Academy', '-mine');
    $admin = adminOf($g);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(CreateSubject::class)->fillForm(['name' => 'Science'])->call('create')->assertHasNoFormErrors();
});

test('a criterion is added to a subject, and an unused one is removed as a soft delete', function () {
    $g = buildGraph();
    $admin = adminOf($g);

    relationTest(CriteriaRelationManager::class, $g['subject'], EditSubject::class, $admin)
        ->callTableAction('create', data: ['name' => 'Working', 'max_score' => 5])
        ->assertHasNoTableActionErrors();

    $working = Criterion::query()->where('name', 'Working')->sole();
    expect($working->subject_id)->toBe($g['subject']->id)->and($working->max_score)->toBe(5);

    relationTest(CriteriaRelationManager::class, $g['subject'], EditSubject::class, $admin)
        ->callTableAction('delete', $working);

    expect(Criterion::withTrashed()->find($working->id))
        ->trashed()->toBeTrue()
        ->version->toBe(2);
});

test('a criterion with marks keeps its maximum and cannot be removed, though its name can be corrected', function () {
    $g = buildGraph();
    $admin = adminOf($g);

    relationTest(CriteriaRelationManager::class, $g['subject'], EditSubject::class, $admin)
        ->assertTableActionHidden('delete', $g['criterion'])
        ->callTableAction('edit', $g['criterion'], data: ['name' => 'Accuracy of working', 'max_score' => 50])
        ->assertHasNoTableActionErrors();

    expect($g['criterion']->fresh())
        ->name->toBe('Accuracy of working')
        ->max_score->toBe(10);
});

test('a class offers a subject once, and stops offering it as a soft delete', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $english = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'English']);

    relationTest(ClassSubjectsRelationManager::class, $g['class'], EditSchoolClass::class, $admin)
        ->callTableAction('create', data: ['subject_id' => $english->id])
        ->assertHasNoTableActionErrors();

    relationTest(ClassSubjectsRelationManager::class, $g['class'], EditSchoolClass::class, $admin)
        ->callTableAction('create', data: ['subject_id' => $g['subject']->id])
        ->assertHasTableActionErrors(['subject_id' => 'unique']);

    $offered = ClassSubject::query()->where('class_id', $g['class']->id)->where('subject_id', $english->id)->sole();

    relationTest(ClassSubjectsRelationManager::class, $g['class'], EditSchoolClass::class, $admin)
        ->callTableAction('delete', $offered);

    expect(ClassSubject::withTrashed()->find($offered->id)->trashed())->toBeTrue();
});

test('a student is enrolled for the current year by default, once a year, and the roster then has them', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $amina = newPupil($g, 'Amina Test');

    relationTest(EnrolmentsRelationManager::class, $g['class'], EditSchoolClass::class, $admin)
        ->mountTableAction('create')
        ->assertTableActionDataSet(['year' => now()->year])
        ->setTableActionData(['student_id' => $amina->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    // Already in a class this year (buildGraph enrols its student for 2026): refused, in any class.
    relationTest(EnrolmentsRelationManager::class, $g['class'], EditSchoolClass::class, $admin)
        ->callTableAction('create', data: ['student_id' => $amina->id, 'year' => now()->year])
        ->assertHasTableActionErrors(['student_id' => 'unique']);

    // The next year is a new enrolment.
    relationTest(EnrolmentsRelationManager::class, $g['class'], EditSchoolClass::class, $admin)
        ->callTableAction('create', data: ['student_id' => $amina->id, 'year' => now()->year + 1])
        ->assertHasNoTableActionErrors();

    $this->withToken(tokenFor($g['teacher']))->getJson('/api/students?class_id='.$g['class']->id)
        ->assertOk()
        ->assertJsonFragment(['name' => 'Amina Test']);
});

test('removing an enrolment soft-deletes it, and the student can be enrolled again', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $brian = newPupil($g, 'Brian Test');
    $enrolment = Enrolment::create(['institution_id' => $g['institution']->id, 'student_id' => $brian->id, 'class_id' => $g['class']->id, 'year' => now()->year]);

    relationTest(EnrolmentsRelationManager::class, $g['class'], EditSchoolClass::class, $admin)
        ->callTableAction('delete', $enrolment)
        ->callTableAction('create', data: ['student_id' => $brian->id, 'year' => now()->year])
        ->assertHasNoTableActionErrors();

    expect(Enrolment::withTrashed()->find($enrolment->id)->trashed())->toBeTrue()
        ->and(Enrolment::query()->where('student_id', $brian->id)->count())->toBe(1);
});

test('a subject teacher is an active teacher account, for a subject the class offers, once', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $colleague = makeColleague($g, 'colleague@example.com');
    $notOffered = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Art']);

    $assign = fn (array $data) => relationTest(TeacherAssignmentsRelationManager::class, $g['class'], EditSchoolClass::class, $admin)
        ->callTableAction('create', data: $data);

    $assign(['subject_id' => $g['subject']->id, 'user_id' => $colleague->id])->assertHasNoTableActionErrors();
    $assign(['subject_id' => $g['subject']->id, 'user_id' => $colleague->id])->assertHasTableActionErrors(['user_id' => 'unique']);
    $assign(['subject_id' => $g['subject']->id, 'user_id' => $admin->id])->assertHasTableActionErrors(['user_id']);
    $assign(['subject_id' => $notOffered->id, 'user_id' => $colleague->id])->assertHasTableActionErrors(['subject_id']);

    expect(TeacherAssignment::query()->where('user_id', $colleague->id)->where('class_id', $g['class']->id)->count())->toBe(1);
});

test('a class teacher is a teacher account, never an admin', function () {
    $g = buildGraph();
    $admin = adminOf($g);
    $this->actingAs($admin, 'admin');
    asPanelRequestFor($admin);

    Livewire::test(CreateSchoolClass::class)
        ->fillForm(['grade' => '5', 'stream' => 'East', 'class_teacher_id' => $admin->id])
        ->call('create')
        ->assertHasFormErrors(['class_teacher_id']);
});

/* The panel in the app's look (AdminPanelProvider, resources/filament/connected): its fonts and
   theme stylesheet from the host itself, its avatar drawn in the page, and nothing fetched from
   another origin, which Filament's defaults did (ui-avatars.com). */
test('a panel page loads the app\'s fonts and theme from the host and nothing from elsewhere', function () {
    $g = buildGraph();
    $admin = adminOf($g);

    $html = $this->actingAs($admin, 'admin')->get('/admin/classes')->assertOk()->getContent();
    $origin = parse_url(config('app.url'), PHP_URL_HOST);
    preg_match_all('/(?:src|href)="(https?:\/\/[^"]+)"/', $html, $urls);

    expect($html)->toContain('fonts/app/connected/index.css')
        ->toContain('css/app/connected-theme.css')
        ->toContain('data:image/svg+xml;base64,')
        ->not->toContain('ui-avatars.com')
        ->and(collect($urls[1])->map(fn ($url) => parse_url($url, PHP_URL_HOST))->unique()->values()->all())->toBe([$origin])
        ->and(public_path('fonts/app/connected/Fraunces-VF.woff2'))->toBeFile()
        ->and(public_path('fonts/app/connected/Figtree-VF.woff2'))->toBeFile();
});

test('a primary button is the brand with white text', function () {
    // The panel registers its colours as it serves a page.
    $html = $this->get('/admin/login')->assertOk()->getContent();
    $primary = FilamentColor::getColor('primary');

    expect(Color::convertToHex($primary[600]))->toBe('#c1440e')
        ->and(Color::isTextContrastRatioAccessible($primary[600], 'oklch(1 0 0)'))->toBeTrue()
        ->and(Color::isTextContrastRatioAccessible($primary[500], 'oklch(1 0 0)'))->toBeTrue()
        ->and($html)->toContain('fi-bg-color-600 hover:fi-bg-color-500');
});

arch('no API controller depends on the admin panel')
    ->expect('App\Http\Controllers')
    ->not->toUse('Filament');

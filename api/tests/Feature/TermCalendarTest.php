<?php

use App\Filament\Resources\Terms\Pages\ListTerms;
use App\Models\Assessment;
use App\Models\Term;
use App\Models\User;
use App\Support\CurrentInstitution;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/* ADR 0014, decision 4: the term calendar an admin keeps. The rules are the Term model's; the
   panel tests at the end check only that the screen asks the model and reports its answer. */

const TERMS_MIGRATION = 'database/migrations/2026_10_13_000002_create_terms_table.php';

function calendarAdmin(array $g, string $email = 'office-calendar@example.com'): User
{
    return makeColleague($g, $email, admin: true);
}

/** [year, term, open] for each live term of the institution, in calendar order. */
function calendarOf(array $g): array
{
    return Term::query()->where('institution_id', $g['institution']->id)->orderBy('year')->orderBy('term')->get()
        ->map(fn (Term $t) => [$t->year, $t->term, $t->isOpen()])->all();
}

test('an admin opens a term, recording when and by whom, and the change reaches the sync log', function () {
    $g = buildGraph(openTerm: false);
    $admin = calendarAdmin($g);
    $this->travelTo('2026-05-04 05:00:00');

    $term = Term::open($admin, 2026, 2);

    expect($term->only(['year', 'term', 'opened_by', 'ended_at']))->toBe(['year' => 2026, 'term' => 2, 'opened_by' => $admin->id, 'ended_at' => null])
        ->and($term->opened_at->toDateTimeString())->toBe('2026-05-04 05:00:00')
        ->and(Term::current($g['institution']->id)->is($term))->toBeTrue();
    expect(DB::table('sync_changes')->where('table', 'terms')->where('record_id', $term->id)->exists())->toBeTrue();
});

test('opening the next term ends the open one at the same instant', function () {
    $g = buildGraph(openTerm: false);
    $admin = calendarAdmin($g);
    $first = Term::open($admin, 2026, 2);
    $this->travelTo('2026-09-01 05:00:00');

    $next = Term::open($admin, 2026, 3);

    expect($first->fresh()->ended_at->equalTo($next->opened_at))->toBeTrue()
        ->and($first->fresh()->ended_by)->toBe($admin->id);
    expect(calendarOf($g))->toBe([[2026, 2, false], [2026, 3, true]]);
});

test('a term must come after every term recorded, but need not be the very next one', function () {
    $g = buildGraph(openTerm: false);
    $admin = calendarAdmin($g);
    Term::open($admin, 2026, 2);

    expect(fn () => Term::open($admin, 2026, 2))->toThrow(DomainException::class, 'does not come after Term 2, 2026');
    expect(fn () => Term::open($admin, 2026, 1))->toThrow(DomainException::class, 'does not come after Term 2, 2026');
    expect(fn () => Term::open($admin, 2025, 3))->toThrow(DomainException::class, 'does not come after Term 2, 2026');
    expect(fn () => Term::open($admin, 2026, 4))->toThrow(DomainException::class, 'Term 1, 2, or 3');

    Term::open($admin, 2027, 1);
    expect(calendarOf($g))->toBe([[2026, 2, false], [2027, 1, true]]);
});

test('the suggested next term follows Term 3 with Term 1 of the next year', function () {
    $g = buildGraph(openTerm: false);
    $admin = calendarAdmin($g);
    expect(Term::suggestedNext($g['institution']->id))->toBe(['year' => null, 'term' => null]);

    Term::open($admin, 2026, 2);
    expect(Term::suggestedNext($g['institution']->id))->toBe(['year' => 2026, 'term' => 3]);

    Term::open($admin, 2026, 3);
    expect(Term::suggestedNext($g['institution']->id))->toBe(['year' => 2027, 'term' => 1]);
});

test('ending the open term for the holidays leaves none open, and with none open there is nothing to end', function () {
    $g = buildGraph(openTerm: false);
    $admin = calendarAdmin($g);
    Term::open($admin, 2026, 3);

    Term::endCurrent($admin);

    expect(Term::current($g['institution']->id))->toBeNull();
    expect(fn () => Term::endCurrent($admin))->toThrow(DomainException::class, 'No term is open.');

    Term::open($admin, 2027, 1);
    expect(calendarOf($g))->toBe([[2026, 3, false], [2027, 1, true]]);
});

test('the database itself refuses a second open term in one institution', function () {
    $g = buildGraph(openTerm: false);
    Term::open(calendarAdmin($g), 2026, 2);

    expect(fn () => Term::create(['institution_id' => $g['institution']->id, 'year' => 2026, 'term' => 3, 'opened_at' => now()]))
        ->toThrow(QueryException::class, 'terms_one_open');
});

test('withdrawing a term opened by mistake reopens the term its opening ended', function () {
    $g = buildGraph(openTerm: false);
    $admin = calendarAdmin($g);
    $previous = Term::open($admin, 2026, 2);
    $mistake = Term::open($admin, 2026, 3);

    $mistake->withdraw($admin);

    expect($mistake->fresh()->trashed())->toBeTrue();
    expect($previous->fresh()->only(['ended_at', 'ended_by']))->toBe(['ended_at' => null, 'ended_by' => null]);
    expect(calendarOf($g))->toBe([[2026, 2, true]]);

    Term::open($admin, 2026, 3);
    expect(calendarOf($g))->toBe([[2026, 2, false], [2026, 3, true]]);
});

test('withdrawing leaves a term ended for the holidays ended, since the opening did not end it', function () {
    $g = buildGraph(openTerm: false);
    $admin = calendarAdmin($g);
    $previous = Term::open($admin, 2026, 2);
    $this->travelTo(now()->addDay());
    Term::endCurrent($admin);
    $this->travelTo(now()->addWeek());
    $mistake = Term::open($admin, 2026, 3);

    $mistake->withdraw($admin);

    expect($previous->fresh()->isOpen())->toBeFalse();
    expect(Term::current($g['institution']->id))->toBeNull();
});

test('a term that an assessment names cannot be withdrawn, and an ended term cannot be withdrawn at all', function () {
    $g = buildGraph(openTerm: false);
    $admin = calendarAdmin($g);
    $ended = Term::open($admin, 2026, 1);
    $open = Term::open($admin, 2026, 2);
    Assessment::create([...$g['assessment']->only(['institution_id', 'class_id', 'subject_id', 'created_by']),
        'name' => 'CAT 1', 'term' => 2, 'year' => 2026, 'date' => '2026-06-01', 'status' => 'scheduled']);

    expect(fn () => $open->withdraw($admin))->toThrow(DomainException::class, 'Term 2, 2026 already has assessments');
    expect(fn () => $ended->withdraw($admin))->toThrow(DomainException::class, 'Only the open term can be withdrawn.');
    expect(calendarOf($g))->toBe([[2026, 1, false], [2026, 2, true]]);
});

test('only an active admin keeps the calendar: a teacher, even a Director of Studies, may not', function () {
    $g = buildGraph(openTerm: false);
    $director = makeColleague($g, 'dos-calendar@example.com', directsStudies: true);
    $admin = calendarAdmin($g);
    $open = Term::open($admin, 2026, 2);
    $gone = calendarAdmin($g, 'gone-calendar@example.com');
    $gone->update(['deactivated_at' => now()]);

    foreach ([$director, $g['teacher'], $gone] as $user) {
        expect(fn () => Term::open($user, 2026, 3))->toThrow(AuthorizationException::class);
        expect(fn () => Term::endCurrent($user))->toThrow(AuthorizationException::class);
        expect(fn () => $open->withdraw($user))->toThrow(AuthorizationException::class);
    }
    expect(calendarOf($g))->toBe([[2026, 2, true]]);
});

test('each institution keeps its own calendar', function () {
    $a = buildGraph('School A', '-cal-a', false);
    $b = buildGraph('School B', '-cal-b', false);
    $adminA = calendarAdmin($a, 'office-cal-a@example.com');
    $adminB = calendarAdmin($b, 'office-cal-b@example.com');
    $termA = Term::open($adminA, 2026, 3);

    Term::open($adminB, 2026, 2);

    expect($termA->fresh()->isOpen())->toBeTrue();
    expect(calendarOf($a))->toBe([[2026, 3, true]]);
    expect(calendarOf($b))->toBe([[2026, 2, true]]);
    expect(fn () => $termA->withdraw($adminB))->toThrow(AuthorizationException::class);
});

test('the migration records each term assessments already name, as ended, opens none, and logs them for pull', function () {
    $g = buildGraph('School A', '-cal-mig', false);
    Assessment::create([...$g['assessment']->only(['institution_id', 'class_id', 'subject_id', 'created_by']),
        'name' => 'End of Term', 'term' => 2, 'year' => 2026, 'date' => '2026-07-20', 'status' => 'scheduled']);

    Artisan::call('migrate:rollback', ['--path' => TERMS_MIGRATION, '--force' => true]);
    Artisan::call('migrate', ['--path' => TERMS_MIGRATION, '--force' => true]);

    expect(calendarOf($g))->toBe([[2026, 1, false], [2026, 2, false]]);
    expect(Term::query()->whereNotNull('opened_at')->exists())->toBeFalse();
    $logged = DB::table('sync_changes')->where('table', 'terms')->orderBy('seq')->get();
    expect($logged)->toHaveCount(2)
        ->and(json_decode($logged[0]->fields, true))->toMatchArray(['year' => 2026, 'term' => 1])
        ->and(Str::isUuid($logged[0]->record_id))->toBeTrue();

    app('auth')->forgetGuards();
    $pulled = $this->withToken(tokenFor($g['teacher']))->getJson('/api/sync?since=0')->assertOk()->json('changes');
    expect(collect($pulled)->where('table', 'terms')->pluck('fields.term')->all())->toBe([1, 2]);
});

describe('the panel', function () {
    beforeEach(function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    });

    function asCalendarRequestFor(User $user): void
    {
        app(CurrentInstitution::class)->reset();
        app(CurrentInstitution::class)->beginRequest($user->institution_id);
    }

    test('lists the calendar, and opening a term suggests the one after the latest', function () {
        $g = buildGraph(openTerm: false);
        $admin = calendarAdmin($g);
        Term::open($admin, 2026, 3);
        $this->actingAs($admin, 'admin');
        asCalendarRequestFor($admin);

        $this->get('/admin/terms')->assertOk()->assertSee('Term 3, 2026');
        Livewire::test(ListTerms::class)
            ->mountAction('openTerm')
            ->assertActionDataSet(['year' => 2027, 'term' => 1])
            ->callMountedAction()
            ->assertNotified('Term 1, 2027 is open');

        expect(calendarOf($g))->toBe([[2026, 3, false], [2027, 1, true]]);
    });

    test('reports what the model refused, and changes nothing', function () {
        $g = buildGraph(openTerm: false);
        $admin = calendarAdmin($g);
        Term::open($admin, 2026, 3);
        $this->actingAs($admin, 'admin');
        asCalendarRequestFor($admin);

        Livewire::test(ListTerms::class)
            ->callAction('openTerm', ['year' => 2026, 'term' => 1])
            ->assertNotified('Term 1, 2026 does not come after Term 3, 2026, the latest term recorded.');

        expect(calendarOf($g))->toBe([[2026, 3, true]]);
    });

    test('ends the current term, and withdraws a term opened by mistake', function () {
        $g = buildGraph(openTerm: false);
        $admin = calendarAdmin($g);
        Term::open($admin, 2026, 2);
        $mistake = Term::open($admin, 2026, 3);
        $this->actingAs($admin, 'admin');
        asCalendarRequestFor($admin);

        Livewire::test(ListTerms::class)->callTableAction('withdraw', $mistake)->assertNotified('Term 3, 2026 was withdrawn');
        expect(calendarOf($g))->toBe([[2026, 2, true]]);

        Livewire::test(ListTerms::class)->callAction('endTerm')->assertNotified('Term 2, 2026 has ended');
        expect(Term::current($g['institution']->id))->toBeNull();
        Livewire::test(ListTerms::class)->assertActionHidden('endTerm');
    });
});

<?php

use App\Http\Middleware\ResolveInstitution;
use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Comment;
use App\Models\Conflict;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Institution;
use App\Models\Mark;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Result;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectModeration;
use App\Models\TeacherAssignment;
use App\Models\UnlockNote;
use App\Models\User;
use App\Support\CurrentInstitution;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* Ticket #41: an Eloquent global scope for institution_id and a middleware
   that resolves the institution from the authenticated user
   (docs/spec/access-model.md, Institution scoping). "Done when" asks for a
   Pest test that authenticates as a user of school A, queries every model,
   and sees no row belonging to school B, including through relationships;
   the middleware and the create-time guard ("the client never asserts its
   institution") get their own focused tests first. */

test('the middleware resolves the current institution from the authenticated user, and leaves it unset with none', function () {
    $g = buildGraph('School A', '-mw');
    $currentInstitution = app(CurrentInstitution::class);

    $passThrough = fn ($request) => new Response;

    $authenticated = Request::create('/');
    $authenticated->setUserResolver(fn () => $g['teacher']);

    app(ResolveInstitution::class)->handle($authenticated, $passThrough);
    expect($currentInstitution->id())->toBe($g['institution']->id);

    $currentInstitution->set(null);
    $anonymous = Request::create('/');
    $anonymous->setUserResolver(fn () => null);

    app(ResolveInstitution::class)->handle($anonymous, $passThrough);
    expect($currentInstitution->id())->toBeNull();
});

test('institution_id is auto-filled from context when omitted, and rejected when it mismatches', function () {
    $g = buildGraph('School A', '-guard');
    app(CurrentInstitution::class)->set($g['institution']->id);

    $autoFilled = Subject::create(['name' => 'Auto-filled']);
    expect($autoFilled->institution_id)->toBe($g['institution']->id);

    $matching = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Matching']);
    expect($matching->institution_id)->toBe($g['institution']->id);

    $otherInstitution = Institution::create(['name' => 'Someone Else']);
    expect(fn () => Subject::create(['institution_id' => $otherInstitution->id, 'name' => 'Mismatched']))
        ->toThrow(InvalidArgumentException::class);
});

test('with no institution resolved, institution_id passes through exactly as given', function () {
    // The seeder/console escape hatch: CurrentInstitution is never set outside
    // a request that went through ResolveInstitution, so nothing here needs
    // to set it, matching every other test in this file that doesn't call
    // CurrentInstitution::set() before creating rows.
    $institution = Institution::create(['name' => 'Bootstrapped School']);

    $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Passed through']);
    expect($subject->institution_id)->toBe($institution->id);
});

test('authenticated as a user of school A, every model is scoped to A and sees nothing of school B, including through relationships', function () {
    // Both schools' full graphs are built with no institution resolved yet
    // (the same trusted, out-of-band path every other test in this file
    // uses): setting the context to A before B exists would trip the
    // create-time guard the moment B's rows tried to carry B's own
    // institution_id. The context is only set once both schools' data is
    // in place, matching the real sequence: seed, then authenticate.
    $a = buildGraph('School A', '-a');
    $b = buildGraph('School B', '-b');

    // The remaining tables, built the same way EloquentModelRelationshipsTest
    // covers their relationships, checked the same way.
    $conflictA = Conflict::create([
        'institution_id' => $a['institution']->id,
        'mark_id' => $a['mark']->id,
        'base_version' => 0,
        'side_a' => ['editId' => 'e1', 'userId' => $a['teacher']->id, 'markKind' => 'score', 'score' => 8, 'at' => now()->toIso8601String()],
        'side_b' => ['editId' => 'e2', 'userId' => $a['teacher']->id, 'markKind' => 'score', 'score' => 6, 'at' => now()->toIso8601String()],
    ]);
    $conflictB = Conflict::create([
        'institution_id' => $b['institution']->id,
        'mark_id' => $b['mark']->id,
        'base_version' => 0,
        'side_a' => ['editId' => 'e1', 'userId' => $b['teacher']->id, 'markKind' => 'score', 'score' => 8, 'at' => now()->toIso8601String()],
        'side_b' => ['editId' => 'e2', 'userId' => $b['teacher']->id, 'markKind' => 'score', 'score' => 6, 'at' => now()->toIso8601String()],
    ]);

    $resultA = Result::create(['institution_id' => $a['institution']->id, 'assessment_id' => $a['assessment']->id, 'student_id' => $a['student']->id, 'total' => 8, 'max' => 10, 'level' => 'ME']);
    $resultB = Result::create(['institution_id' => $b['institution']->id, 'assessment_id' => $b['assessment']->id, 'student_id' => $b['student']->id, 'total' => 8, 'max' => 10, 'level' => 'ME']);

    $commentA = Comment::create(['institution_id' => $a['institution']->id, 'assessment_id' => $a['assessment']->id, 'student_id' => $a['student']->id, 'author_id' => $a['teacher']->id, 'body' => 'Good.', 'state' => 'draft']);
    $commentB = Comment::create(['institution_id' => $b['institution']->id, 'assessment_id' => $b['assessment']->id, 'student_id' => $b['student']->id, 'author_id' => $b['teacher']->id, 'body' => 'Good.', 'state' => 'draft']);

    $reportA = Report::create(['institution_id' => $a['institution']->id, 'assessment_id' => $a['assessment']->id, 'student_id' => $a['student']->id, 'generated_at' => now(), 's3_key' => 'a.pdf']);
    $reportB = Report::create(['institution_id' => $b['institution']->id, 'assessment_id' => $b['assessment']->id, 'student_id' => $b['student']->id, 'generated_at' => now(), 's3_key' => 'b.pdf']);

    $notificationA = Notification::create(['institution_id' => $a['institution']->id, 'user_id' => $a['teacher']->id, 'kind' => 'sync-conflict', 'tone' => 'warning', 'title' => 'A', 'body' => 'A', 'unread' => true]);
    $notificationB = Notification::create(['institution_id' => $b['institution']->id, 'user_id' => $b['teacher']->id, 'kind' => 'sync-conflict', 'tone' => 'warning', 'title' => 'B', 'body' => 'B', 'unread' => true]);

    $unlockNoteA = UnlockNote::create(['institution_id' => $a['institution']->id, 'assessment_id' => $a['assessment']->id, 'user_id' => $a['teacher']->id, 'note' => 'A']);
    $unlockNoteB = UnlockNote::create(['institution_id' => $b['institution']->id, 'assessment_id' => $b['assessment']->id, 'user_id' => $b['teacher']->id, 'note' => 'B']);

    // Authenticate as a user of school A: from here on, every query below
    // must see only what school A can see.
    app(CurrentInstitution::class)->set($a['institution']->id);

    $scopedModels = [
        Subject::class => 'subject',
        Criterion::class => 'criterion',
        SchoolClass::class => 'class',
        ClassSubject::class => 'classSubject',
        TeacherAssignment::class => 'teacherAssignment',
        SubjectModeration::class => 'subjectModeration',
        Student::class => 'student',
        Enrolment::class => 'enrolment',
        Assessment::class => 'assessment',
        Mark::class => 'mark',
        User::class => 'teacher',
    ];

    // Direct queries: each model type sees only its own institution's rows,
    // and a row that does exist, just under school B, resolves to nothing,
    // matching access-model.md's "a request naming another institution's
    // record simply finds nothing."
    foreach ($scopedModels as $modelClass => $key) {
        expect($modelClass::count())->toBe(1, "{$modelClass} should only see school A's row");
        expect($modelClass::find($a[$key]->id))->not->toBeNull();
        expect($modelClass::find($b[$key]->id))->toBeNull();
    }

    $remaining = [
        Conflict::class => [$conflictA, $conflictB],
        Result::class => [$resultA, $resultB],
        Comment::class => [$commentA, $commentB],
        Report::class => [$reportA, $reportB],
        Notification::class => [$notificationA, $notificationB],
        UnlockNote::class => [$unlockNoteA, $unlockNoteB],
    ];

    foreach ($remaining as $modelClass => [$rowA, $rowB]) {
        expect($modelClass::count())->toBe(1, "{$modelClass} should only see school A's row");
        expect($modelClass::find($rowA->id))->not->toBeNull();
        expect($modelClass::find($rowB->id))->toBeNull();
    }

    // Through relationships: a belongsTo/hasMany traversal from an A row
    // never reaches a B row, because the related model's own global scope
    // applies to the query the relationship builds, not just to a bare
    // Model::query() call.
    expect($a['mark']->assessment->schoolClass->institution->is($a['institution']))->toBeTrue();
    expect($a['institution']->students)->toHaveCount(1);
    expect($a['institution']->students->first()->is($a['student']))->toBeTrue();

    // Institution itself is deliberately not scoped (data-model.md,
    // Conventions): both rows stay visible regardless of context.
    expect(Institution::count())->toBe(2);
});

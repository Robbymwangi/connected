<?php

use App\Models\Assessment;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use Illuminate\Support\Facades\Gate;

/* The assessment lifecycle is scoped to whoever is answerable for the sitting: its
   creator, or a teacher assigned to its class and subject. docs/spec/access-model.md,
   "Once an assessment exists, its lifecycle is not unrestricted". Editing is scoped
   the same way finalizing is; creating and grading stay unrestricted. This is the
   whole permission matrix, at the policy, so a failure names the rule that moved. */

test('the creator may update and finalize their assessment', function () {
    $g = buildGraph('School A', '-policy-creator');

    expect(Gate::forUser($g['teacher'])->allows('update', $g['assessment']))->toBeTrue();
    expect(Gate::forUser($g['teacher'])->allows('finalize', $g['assessment']))->toBeTrue();
});

test('a teacher assigned to the class and subject may update it without having created it', function () {
    $g = buildGraph('School A', '-policy-assigned');
    $assigned = makeColleague($g, 'assigned-policy@example.com', assigned: true);

    expect(Gate::forUser($assigned)->allows('update', $g['assessment']))->toBeTrue();
});

test('a same-school user who neither created nor teaches it may not update it, an admin included', function (bool $admin) {
    $g = buildGraph('School A', '-policy-bystander');
    $bystander = makeColleague($g, 'bystander-policy@example.com', admin: $admin);

    expect(Gate::forUser($bystander)->allows('update', $g['assessment']))->toBeFalse();
    expect(Gate::forUser($bystander)->allows('finalize', $g['assessment']))->toBeFalse();
})->with(['a teacher' => false, 'an admin' => true]);

test('an assignment to the same class but another subject is not enough', function () {
    $g = buildGraph('School A', '-policy-other-subject');
    $colleague = makeColleague($g, 'other-subject-policy@example.com');
    $otherSubject = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Science']);
    TeacherAssignment::create([
        'institution_id' => $g['institution']->id,
        'user_id' => $colleague->id,
        'class_id' => $g['class']->id,
        'subject_id' => $otherSubject->id,
    ]);

    expect(Gate::forUser($colleague)->allows('update', $g['assessment']))->toBeFalse();
});

test('an assignment to the same subject but another class is not enough', function () {
    $g = buildGraph('School A', '-policy-other-class');
    $colleague = makeColleague($g, 'other-class-policy@example.com');
    $otherClass = SchoolClass::create([
        'institution_id' => $g['institution']->id,
        'grade' => '5',
        'stream' => 'East',
        'class_teacher_id' => $g['teacher']->id,
    ]);
    TeacherAssignment::create([
        'institution_id' => $g['institution']->id,
        'user_id' => $colleague->id,
        'class_id' => $otherClass->id,
        'subject_id' => $g['subject']->id,
    ]);

    expect(Gate::forUser($colleague)->allows('update', $g['assessment']))->toBeFalse();
});

test('a user of another institution may not update it', function () {
    $a = buildGraph('School A', '-policy-tenant-a');
    $b = buildGraph('School B', '-policy-tenant-b');

    expect(Gate::forUser($b['teacher'])->allows('update', $a['assessment']))->toBeFalse();
});

test('a deactivated or deleted creator may not update their assessment', function (string $state) {
    $g = buildGraph('School A', '-policy-'.$state);

    if ($state === 'deactivated') {
        $g['teacher']->update(['deactivated_at' => now()]);
    } else {
        $g['teacher']->delete();
    }

    expect(Gate::forUser($g['teacher'])->allows('update', $g['assessment']))->toBeFalse();
})->with(['deactivated', 'deleted']);

test('creating an assessment stays unrestricted by who', function () {
    $g = buildGraph('School A', '-policy-create');
    $bystander = makeColleague($g, 'create-policy@example.com');

    expect(Gate::forUser($bystander)->allows('create', Assessment::class))->toBeTrue();
});

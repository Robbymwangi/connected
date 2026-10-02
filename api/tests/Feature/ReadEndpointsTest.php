<?php

use App\Models\Enrolment;
use App\Models\SchoolClass;
use App\Models\Student;

/* Ticket #48 (docs/build-plan.md 2.1): classes, subjects (with their
   criteria as the rubric), students, and assessments, as JSON resources.
   Reads are unrestricted for any authenticated user within the institution
   (docs/spec/access-model.md, The principle); InstitutionScope does the
   actual filtering on every one of these, not a condition in a controller,
   so the cross-institution test below is the same shape for all four. */

test('all four endpoints refuse an unauthenticated request', function () {
    $this->getJson('/api/classes')->assertUnauthorized();
    $this->getJson('/api/subjects')->assertUnauthorized();
    $this->getJson('/api/students')->assertUnauthorized();
    $this->getJson('/api/assessments')->assertUnauthorized();
});

test('a class comes back with its teacher and the subjects it offers', function () {
    $g = buildGraph('School A', '-classes-shape');
    $token = tokenFor($g['teacher']);

    $this->withToken($token)->getJson('/api/classes')
        ->assertOk()
        ->assertJson(['data' => [[
            'id' => $g['class']->id,
            'grade' => '4',
            'stream' => 'West',
            'class_teacher' => ['id' => $g['teacher']->id, 'name' => 'T. Teacher'],
            'subjects' => [['id' => $g['subject']->id, 'name' => 'Maths']],
        ]]]);
});

test('a subject comes back with its criteria as the rubric', function () {
    $g = buildGraph('School A', '-subjects-shape');
    $token = tokenFor($g['teacher']);

    $this->withToken($token)->getJson('/api/subjects')
        ->assertOk()
        ->assertJson(['data' => [[
            'id' => $g['subject']->id,
            'name' => 'Maths',
            'criteria' => [['id' => $g['criterion']->id, 'name' => 'Accuracy', 'max_score' => 10]],
        ]]]);
});

test('a plain student list carries no enrolment, since class is a fact of a year, not of the student', function () {
    $g = buildGraph('School A', '-students-plain');
    $token = tokenFor($g['teacher']);

    $this->withToken($token)->getJson('/api/students')
        ->assertOk()
        ->assertJson(['data' => [[
            'id' => $g['student']->id,
            'name' => 'S. Student',
            'gender' => 'F',
        ]]])
        ->assertJsonMissingPath('data.0.enrolments');
});

test('filtering students by class_id returns the current-year roster, with the matching enrolment attached', function () {
    $g = buildGraph('School A', '-students-roster');
    $token = tokenFor($g['teacher']);

    // No hardcoded calendar year, and no frozen clock: StudentsController's
    // default is now()->year, so the fixtures are built relative to the
    // same now() instead of a second, independently hardcoded guess that
    // would only coincidentally agree with it. buildGraph's own enrolment
    // is pinned to a fixed year (2026 at the time of writing); overridden
    // here to whatever year this actually runs in, since this test's whole
    // point is the current-year default, not that specific year.
    $currentYear = now()->year;
    $g['enrolment']->update(['year' => $currentYear]);

    // A second student enrolled in a different class must not appear.
    $elsewhere = Student::create(['institution_id' => $g['institution']->id, 'name' => 'Elsewhere', 'gender' => 'M', 'dob' => '2015-01-01']);
    $otherClass = SchoolClass::create(['institution_id' => $g['institution']->id, 'grade' => '5', 'stream' => 'East']);
    Enrolment::create(['institution_id' => $g['institution']->id, 'student_id' => $elsewhere->id, 'class_id' => $otherClass->id, 'year' => $currentYear]);

    // A former student of this same class, in the prior year, must not
    // appear either: class_id alone means today's roster, not every year
    // this class has ever had (docs/spec/data-model.md, enrolments).
    $formerYear = $currentYear - 1;
    $former = Student::create(['institution_id' => $g['institution']->id, 'name' => 'Former Student', 'gender' => 'F', 'dob' => '2014-01-01']);
    Enrolment::create(['institution_id' => $g['institution']->id, 'student_id' => $former->id, 'class_id' => $g['class']->id, 'year' => $formerYear]);

    $this->withToken($token)->getJson('/api/students?class_id='.$g['class']->id)
        ->assertOk()
        ->assertJson(['data' => [[
            'id' => $g['student']->id,
            'enrolments' => [['class_id' => $g['class']->id, 'year' => $currentYear]],
        ]]])
        ->assertJsonCount(1, 'data');

    // The same former student is exactly who an explicit, historical year
    // is for.
    $this->withToken($token)->getJson('/api/students?class_id='.$g['class']->id.'&year='.$formerYear)
        ->assertOk()
        ->assertJson(['data' => [[
            'id' => $former->id,
            'enrolments' => [['class_id' => $g['class']->id, 'year' => $formerYear]],
        ]]])
        ->assertJsonCount(1, 'data');
});

test('a nonpositive year is rejected rather than silently ignored', function () {
    $g = buildGraph('School A', '-students-bad-year');
    $token = tokenFor($g['teacher']);

    $this->withToken($token)->getJson('/api/students?year=0')->assertStatus(422);
});

test('filtering students by year alone is honoured, not silently dropped', function () {
    $g = buildGraph('School A', '-students-year');
    $token = tokenFor($g['teacher']);

    $otherYear = Student::create(['institution_id' => $g['institution']->id, 'name' => 'Other Year', 'gender' => 'M', 'dob' => '2014-01-01']);
    Enrolment::create(['institution_id' => $g['institution']->id, 'student_id' => $otherYear->id, 'class_id' => $g['class']->id, 'year' => 2025]);

    $this->withToken($token)->getJson('/api/students?year=2026')
        ->assertOk()
        ->assertJson(['data' => [[
            'id' => $g['student']->id,
            'enrolments' => [['class_id' => $g['class']->id, 'year' => 2026]],
        ]]])
        ->assertJsonCount(1, 'data');
});

test('an assessment comes back with its subject, and class_id filters to one class', function () {
    $g = buildGraph('School A', '-assessments-shape');
    $token = tokenFor($g['teacher']);

    $this->withToken($token)->getJson('/api/assessments?class_id='.$g['class']->id)
        ->assertOk()
        ->assertJson(['data' => [[
            'id' => $g['assessment']->id,
            'class_id' => $g['class']->id,
            'subject' => ['id' => $g['subject']->id, 'name' => 'Maths'],
            'name' => 'CAT 1',
            'term' => 1,
            'year' => 2026,
            'status' => 'scheduled',
        ]]]);
});

test('every endpoint is scoped to the signed-in account\'s own institution, not just its own rows', function () {
    $a = buildGraph('School A', '-cross-a');
    $b = buildGraph('School B', '-cross-b');
    $token = tokenFor($a['teacher']);

    $client = $this->withToken($token);

    $classIds = $client->getJson('/api/classes')->json('data.*.id');
    expect($classIds)->toContain($a['class']->id)->not->toContain($b['class']->id);

    $subjectIds = $client->getJson('/api/subjects')->json('data.*.id');
    expect($subjectIds)->toContain($a['subject']->id)->not->toContain($b['subject']->id);

    $studentIds = $client->getJson('/api/students')->json('data.*.id');
    expect($studentIds)->toContain($a['student']->id)->not->toContain($b['student']->id);

    $assessmentIds = $client->getJson('/api/assessments')->json('data.*.id');
    expect($assessmentIds)->toContain($a['assessment']->id)->not->toContain($b['assessment']->id);

    // Naming school B's own class by id, as a filter, finds nothing: it
    // does not exist from school A's side, the same as an unknown id
    // (docs/spec/access-model.md, Institution scoping).
    $client->getJson('/api/assessments?class_id='.$b['class']->id)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

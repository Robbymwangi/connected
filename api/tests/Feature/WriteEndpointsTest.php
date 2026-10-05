<?php

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

test('a non-admin teacher can write a mark for an unassigned class and subject', function () {
    $graph = buildGraph('School A', '-mark-write');
    $forgedEditor = User::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'Other Teacher',
        'email' => 'other-mark-write@example.com',
        'password' => 'a-hashed-password',
    ]);
    $schoolClass = SchoolClass::create([
        'institution_id' => $graph['institution']->id,
        'grade' => '5',
        'stream' => 'East',
    ]);
    ClassSubject::create([
        'institution_id' => $graph['institution']->id,
        'class_id' => $schoolClass->id,
        'subject_id' => $graph['subject']->id,
    ]);
    $student = Student::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'A. Student',
        'gender' => 'M',
        'dob' => '2015-03-01',
    ]);
    Enrolment::create([
        'institution_id' => $graph['institution']->id,
        'student_id' => $student->id,
        'class_id' => $schoolClass->id,
        'year' => 2026,
    ]);
    $assessment = Assessment::create([
        'institution_id' => $graph['institution']->id,
        'class_id' => $schoolClass->id,
        'subject_id' => $graph['subject']->id,
        'name' => 'CAT 2',
        'term' => 1,
        'year' => 2026,
        'date' => '2026-03-01',
        'status' => 'scheduled',
        'created_by' => $graph['teacher']->id,
    ]);

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/marks', [
        'assessment_id' => $assessment->id,
        'student_id' => $student->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'score',
        'score' => 8,
        'institution_id' => '00000000-0000-4000-8000-000000000000',
        'last_edited_by' => $forgedEditor->id,
        'version' => 99,
    ]);

    $response->assertCreated()->assertJsonPath('data.mark_kind', 'score');

    $mark = Mark::query()->findOrFail($response->json('data.id'));
    $this->assertModelExists($mark);
    expect($mark->institution_id)->toBe($graph['institution']->id)
        ->and($mark->last_edited_by)->toBe($graph['teacher']->id)
        ->and($mark->version)->toBe(0);
});

test('a concurrent first write to a mark cell updates the winning row', function () {
    $graph = buildGraph('School A', '-concurrent-mark-write');
    $student = Student::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'Concurrent Student',
        'gender' => 'F',
        'dob' => '2015-03-01',
    ]);
    Enrolment::create([
        'institution_id' => $graph['institution']->id,
        'student_id' => $student->id,
        'class_id' => $graph['class']->id,
        'year' => $graph['assessment']->year,
    ]);

    $concurrentWriteInserted = false;
    DB::listen(function (QueryExecuted $query) use ($graph, $student, &$concurrentWriteInserted): void {
        if ($concurrentWriteInserted
            || ! str_contains($query->sql, 'from "marks"')
            || ! str_contains($query->sql, '"assessment_id" = ?')) {
            return;
        }

        $concurrentWriteInserted = true;
        Mark::create([
            'institution_id' => $graph['institution']->id,
            'assessment_id' => $graph['assessment']->id,
            'student_id' => $student->id,
            'criterion_id' => $graph['criterion']->id,
            'mark_kind' => 'empty',
            'score' => null,
            'last_edited_by' => $graph['teacher']->id,
        ]);
    });

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/marks', [
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $student->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'score',
        'score' => 8,
    ]);

    $response->assertOk()->assertJsonPath('data.mark_kind', 'score');

    $mark = Mark::query()
        ->where('assessment_id', $graph['assessment']->id)
        ->where('student_id', $student->id)
        ->where('criterion_id', $graph['criterion']->id)
        ->firstOrFail();

    expect($concurrentWriteInserted)->toBeTrue()
        ->and($response->json('data.id'))->toBe($mark->id)
        ->and($mark->score)->toBe(8)
        ->and($mark->version)->toBe(1)
        ->and($mark->last_edited_by)->toBe($graph['teacher']->id);
});

test('a non-admin teacher can create an assessment for an unassigned class and subject', function () {
    $graph = buildGraph('School A', '-assessment-write');
    $schoolClass = SchoolClass::create([
        'institution_id' => $graph['institution']->id,
        'grade' => '5',
        'stream' => 'East',
    ]);
    ClassSubject::create([
        'institution_id' => $graph['institution']->id,
        'class_id' => $schoolClass->id,
        'subject_id' => $graph['subject']->id,
    ]);

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/assessments', [
        'class_id' => $schoolClass->id,
        'subject_id' => $graph['subject']->id,
        'name' => 'CAT 2',
        'term' => 2,
        'year' => 2026,
        'date' => '2026-05-01',
        'institution_id' => '00000000-0000-4000-8000-000000000000',
        'created_by' => '00000000-0000-4000-8000-000000000001',
        'status' => 'finalized',
    ]);

    $response->assertCreated()->assertJsonPath('data.name', 'CAT 2');

    $assessment = Assessment::query()->findOrFail($response->json('data.id'));
    $this->assertModelExists($assessment);
    expect($assessment->institution_id)->toBe($graph['institution']->id)
        ->and($assessment->created_by)->toBe($graph['teacher']->id)
        ->and($assessment->status)->toBe('scheduled');
});

test('an assessment with marks cannot change its class, subject, or year', function () {
    $graph = buildGraph('School A', '-assessment-scope-change');
    $newSubject = Subject::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'Science',
    ]);
    $newClass = SchoolClass::create([
        'institution_id' => $graph['institution']->id,
        'grade' => '5',
        'stream' => 'East',
    ]);
    ClassSubject::create([
        'institution_id' => $graph['institution']->id,
        'class_id' => $newClass->id,
        'subject_id' => $newSubject->id,
    ]);

    $response = $this->withToken(tokenFor($graph['teacher']))->putJson(
        '/api/assessments/'.$graph['assessment']->id,
        [
            'class_id' => $newClass->id,
            'subject_id' => $newSubject->id,
            'name' => 'Moved Assessment',
            'term' => 2,
            'year' => 2027,
            'date' => '2027-05-01',
        ],
    );

    $response->assertInvalid([
        'class_id' => 'cannot change after marks exist',
        'subject_id' => 'cannot change after marks exist',
        'year' => 'cannot change after marks exist',
    ]);

    $assessment = $graph['assessment']->fresh();
    expect($assessment->class_id)->toBe($graph['class']->id)
        ->and($assessment->subject_id)->toBe($graph['subject']->id)
        ->and($assessment->year)->toBe(2026)
        ->and($assessment->version)->toBe(0);
});

test('an assessment with marks can update its name and date without changing scope', function () {
    $graph = buildGraph('School A', '-assessment-metadata-update');

    $response = $this->withToken(tokenFor($graph['teacher']))->putJson(
        '/api/assessments/'.$graph['assessment']->id,
        [
            'class_id' => $graph['class']->id,
            'subject_id' => $graph['subject']->id,
            'name' => 'Updated CAT 1',
            'term' => 1,
            'year' => 2026,
            'date' => '2026-03-01',
        ],
    );

    $response->assertOk()->assertJsonPath('data.name', 'Updated CAT 1');

    $assessment = $graph['assessment']->fresh();
    expect($assessment->class_id)->toBe($graph['class']->id)
        ->and($assessment->subject_id)->toBe($graph['subject']->id)
        ->and($assessment->year)->toBe(2026)
        ->and($assessment->date->toDateString())->toBe('2026-03-01');
});

test('a non-admin teacher can update any mark in the school', function () {
    $graph = buildGraph('School A', '-mark-update');

    $response = $this->withToken(tokenFor($graph['teacher']))->putJson('/api/marks/'.$graph['mark']->id, [
        'mark_kind' => 'absent',
    ]);

    $response->assertOk()->assertJsonPath('data.mark_kind', 'absent');

    $mark = $graph['mark']->fresh();
    expect($mark->score)->toBeNull()
        ->and($mark->version)->toBe(1)
        ->and($mark->last_edited_by)->toBe($graph['teacher']->id);
});

test('a non-admin teacher receives 403 when creating a student', function () {
    $graph = buildGraph('School A', '-student-write');

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/students', [
        'name' => 'New Student',
        'gender' => 'F',
        'dob' => '2015-01-01',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('students', ['name' => 'New Student']);
});

test('a non-admin teacher receives 403 when creating a class', function () {
    $graph = buildGraph('School A', '-class-write');

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/classes', [
        'grade' => '5',
        'stream' => 'East',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('classes', ['grade' => '5', 'stream' => 'East']);
});

test('a non-admin teacher receives 403 when creating a teacher account', function () {
    $graph = buildGraph('School A', '-teacher-write');

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/teachers', [
        'name' => 'New Teacher',
        'email' => 'new-teacher@example.com',
        'password' => 'long-enough-password',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'new-teacher@example.com']);
});

test('an admin can create a student', function () {
    $graph = buildGraph('School A', '-admin-student-write');
    $admin = User::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'A. Admin',
        'email' => 'admin-student-write@example.com',
        'password' => 'a-hashed-password',
        'is_admin' => true,
    ]);

    $response = $this->withToken(tokenFor($admin))->postJson('/api/students', [
        'name' => 'New Student',
        'gender' => 'F',
        'dob' => '2015-01-01',
    ]);

    $response->assertCreated()->assertJsonPath('data.name', 'New Student');
    $student = Student::query()->findOrFail($response->json('data.id'));
    $this->assertModelExists($student);
    expect($student->institution_id)->toBe($graph['institution']->id);
});

test('an admin can create a class', function () {
    $graph = buildGraph('School A', '-admin-class-write');
    $admin = User::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'A. Admin',
        'email' => 'admin-class-write@example.com',
        'password' => 'a-hashed-password',
        'is_admin' => true,
    ]);

    $response = $this->withToken(tokenFor($admin))->postJson('/api/classes', [
        'grade' => '5',
        'stream' => 'East',
        'class_teacher_id' => $graph['teacher']->id,
    ]);

    $response->assertCreated()->assertJsonPath('data.stream', 'East');
    expect($response->json('data.class_teacher.id'))->toBe($graph['teacher']->id);
});

test('an admin can create a teacher account', function () {
    $graph = buildGraph('School A', '-admin-teacher-write');
    $admin = User::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'A. Admin',
        'email' => 'admin-teacher-write@example.com',
        'password' => 'a-hashed-password',
        'is_admin' => true,
    ]);

    $response = $this->withToken(tokenFor($admin))->postJson('/api/teachers', [
        'name' => 'New Teacher',
        'email' => 'new-teacher@example.com',
        'password' => 'long-enough-password',
    ]);

    $response->assertCreated()->assertJsonPath('data.email', 'new-teacher@example.com');
    $teacher = User::query()->where('email', 'new-teacher@example.com')->firstOrFail();
    $this->assertModelExists($teacher);
    expect($teacher->institution_id)->toBe($graph['institution']->id)
        ->and($teacher->password)->not->toBe('long-enough-password');
});

test('write policies allow marks and assessments but reserve roster records to admins', function () {
    $graph = buildGraph('School A', '-policy-matrix');
    $admin = User::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'A. Admin',
        'email' => 'admin-policy-matrix@example.com',
        'password' => 'a-hashed-password',
        'is_admin' => true,
    ]);

    $teacherAbilities = [
        Gate::forUser($graph['teacher'])->allows('create', Mark::class),
        Gate::forUser($graph['teacher'])->allows('update', $graph['mark']),
        Gate::forUser($graph['teacher'])->allows('create', Assessment::class),
        Gate::forUser($graph['teacher'])->allows('update', $graph['assessment']),
        Gate::forUser($graph['teacher'])->allows('create', Student::class),
        Gate::forUser($graph['teacher'])->allows('update', $graph['student']),
        Gate::forUser($graph['teacher'])->allows('create', SchoolClass::class),
        Gate::forUser($graph['teacher'])->allows('update', $graph['class']),
        Gate::forUser($graph['teacher'])->allows('create', User::class),
        Gate::forUser($graph['teacher'])->allows('update', $admin),
    ];
    $adminAbilities = [
        Gate::forUser($admin)->allows('create', Mark::class),
        Gate::forUser($admin)->allows('update', $graph['mark']),
        Gate::forUser($admin)->allows('create', Assessment::class),
        Gate::forUser($admin)->allows('update', $graph['assessment']),
        Gate::forUser($admin)->allows('create', Student::class),
        Gate::forUser($admin)->allows('update', $graph['student']),
        Gate::forUser($admin)->allows('create', SchoolClass::class),
        Gate::forUser($admin)->allows('update', $graph['class']),
        Gate::forUser($admin)->allows('create', User::class),
        Gate::forUser($admin)->allows('update', $graph['teacher']),
    ];

    expect($teacherAbilities)->toBe([true, true, true, true, false, false, false, false, false, false]);
    expect($adminAbilities)->toBe([true, true, true, true, true, true, true, true, true, true]);
});

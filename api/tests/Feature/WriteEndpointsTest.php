<?php

use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Mark;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('unlock returns 401 without an authenticated account', function () {
    $response = $this->postJson('/api/assessments/00000000-0000-4000-8000-000000000001/unlock');

    $response->assertUnauthorized();
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('an admin can unlock without reports and receives the unlocked assessment', function () {
    $graph = buildGraph();
    $graph['teacher']->update(['is_admin' => true]);
    $graph['assessment']->finalize($graph['teacher']);

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson(
        '/api/assessments/'.$graph['assessment']->id.'/unlock',
    );

    $response->assertOk()
        ->assertJsonPath('data.id', $graph['assessment']->id)
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.finalized_at', null)
        ->assertJsonPath('data.subject.id', $graph['subject']->id);
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'scheduled',
        'finalized_at' => null,
        'finalized_by' => null,
        'version' => 3,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('unlock with a report records the authenticated admin rather than client-supplied audit fields', function () {
    $graph = buildGraph();
    $admin = User::factory()->create([
        'institution_id' => $graph['institution']->id,
        'is_admin' => true,
    ]);
    $graph['assessment']->finalize($graph['teacher']);
    $report = Report::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'generated_at' => '2026-10-05 11:00:00',
        's3_key' => 'reports/student.pdf',
    ]);
    $comment = Comment::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'author_id' => $graph['teacher']->id,
        'body' => 'Well done.',
        'state' => 'accepted',
    ]);

    $response = $this->withToken(tokenFor($admin))->postJson(
        '/api/assessments/'.$graph['assessment']->id.'/unlock',
        [
            'note' => 'Correct the recorded score.',
            'user_id' => $graph['teacher']->id,
            'institution_id' => '00000000-0000-4000-8000-000000000000',
            'assessment_id' => '00000000-0000-4000-8000-000000000001',
            'status' => 'reports-generated',
        ],
    );

    $response->assertOk()->assertJsonPath('data.status', 'scheduled');
    $this->assertDatabaseHas('unlock_notes', [
        'assessment_id' => $graph['assessment']->id,
        'institution_id' => $graph['institution']->id,
        'user_id' => $admin->id,
        'note' => 'Correct the recorded score.',
    ]);
    $this->assertDatabaseCount('unlock_notes', 1);
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'scheduled',
        'finalized_at' => null,
        'finalized_by' => null,
        'version' => 3,
    ]);
    $this->assertSoftDeleted($report);
    $this->assertSoftDeleted($comment);
});

test('unlock returns 422 with a resolution-note error when reports exist', function () {
    $graph = buildGraph();
    $graph['teacher']->update(['is_admin' => true]);
    $graph['assessment']->finalize($graph['teacher']);
    $report = Report::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'generated_at' => '2026-10-05 11:00:00',
        's3_key' => 'reports/student.pdf',
    ]);

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson(
        '/api/assessments/'.$graph['assessment']->id.'/unlock',
    );

    $response->assertUnprocessable()
        ->assertInvalid(['note' => 'A resolution note is required when reports exist.']);
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'version' => 2,
    ]);
    $this->assertDatabaseHas('reports', ['id' => $report->id, 'deleted_at' => null, 'version' => 1]);
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('unlock returns 422 when the optional note is not a string', function () {
    $graph = buildGraph();
    $graph['teacher']->update(['is_admin' => true]);
    $graph['assessment']->finalize($graph['teacher']);

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson(
        '/api/assessments/'.$graph['assessment']->id.'/unlock',
        ['note' => ['not a string']],
    );

    $response->assertUnprocessable()->assertInvalid(['note' => 'The note field must be a string.']);
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'version' => 2,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('unlock returns 403 for a non-admin even when they created the assessment', function () {
    $graph = buildGraph();
    $graph['assessment']->finalize($graph['teacher']);

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson(
        '/api/assessments/'.$graph['assessment']->id.'/unlock',
    );

    $response->assertForbidden();
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'version' => 2,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('unlock returns 404 for an assessment in another institution', function () {
    $graph = buildGraph('School A', '-unlock-a');
    $otherSchool = buildGraph('School B', '-unlock-b');
    $graph['assessment']->finalize($graph['teacher']);
    $otherSchool['teacher']->update(['is_admin' => true]);

    $response = $this->withToken(tokenFor($otherSchool['teacher']))->postJson(
        '/api/assessments/'.$graph['assessment']->id.'/unlock',
        ['note' => 'Must not unlock another institution.'],
    );

    $response->assertNotFound();
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'version' => 2,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
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

test('write policies allow marks and assessment creation, scope assessment edits to whoever answers for them, and reserve roster records to admins', function () {
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
    // The admin neither created nor teaches the assessment, so editing it is not theirs;
    // an admin's override is unlock alone (docs/spec/access-model.md), tested elsewhere.
    expect($adminAbilities)->toBe([true, true, true, false, true, true, true, true, true, true]);
});

test('marks and assessments are written over POST /sync alone: the REST writes are gone', function () {
    $graph = buildGraph('School A', '-rest-gone');
    $token = tokenFor($graph['teacher']);

    $this->withToken($token)->postJson('/api/marks', [])->assertNotFound();
    $this->withToken($token)->putJson('/api/marks/'.$graph['mark']->id, [])->assertNotFound();
    $this->withToken($token)->postJson('/api/assessments', [])->assertMethodNotAllowed();
    $this->withToken($token)->putJson('/api/assessments/'.$graph['assessment']->id, [])->assertNotFound();
});

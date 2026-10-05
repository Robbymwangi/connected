<?php

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Comment;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

test('creating a mark returns 422 when the assessment is locked', function (string $status) {
    $graph = buildGraph();
    $graph['assessment']->update(['status' => $status]);
    $criterion = Criterion::create([
        'institution_id' => $graph['institution']->id,
        'subject_id' => $graph['subject']->id,
        'name' => 'Reasoning',
        'max_score' => 10,
    ]);

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/marks', [
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'criterion_id' => $criterion->id,
        'mark_kind' => 'score',
        'score' => 5,
    ]);

    $response->assertUnprocessable()->assertInvalid([
        'assessment_id' => 'Marks cannot be changed while the assessment is finalized. Unlock it first.',
    ]);
    $this->assertDatabaseMissing('marks', [
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'criterion_id' => $criterion->id,
    ]);
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => $status,
        'version' => 1,
    ]);
})->with(['finalized', 'reports-generated']);

test('upserting a mark returns 422 when the assessment is locked', function (string $status) {
    $graph = buildGraph();
    $graph['assessment']->update(['status' => $status]);

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/marks', [
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'absent',
    ]);

    $response->assertUnprocessable()->assertInvalid([
        'assessment_id' => 'Marks cannot be changed while the assessment is finalized. Unlock it first.',
    ]);
    $this->assertDatabaseHas('marks', [
        'id' => $graph['mark']->id,
        'mark_kind' => 'score',
        'score' => 8,
        'version' => 0,
        'last_edited_by' => $graph['teacher']->id,
    ]);
})->with(['finalized', 'reports-generated']);

test('updating a mark returns 422 when the assessment is locked', function (string $status) {
    $graph = buildGraph();
    $graph['assessment']->update(['status' => $status]);

    $response = $this->withToken(tokenFor($graph['teacher']))->putJson('/api/marks/'.$graph['mark']->id, [
        'mark_kind' => 'absent',
    ]);

    $response->assertUnprocessable()->assertInvalid([
        'assessment_id' => 'Marks cannot be changed while the assessment is finalized. Unlock it first.',
    ]);
    $this->assertDatabaseHas('marks', [
        'id' => $graph['mark']->id,
        'mark_kind' => 'score',
        'score' => 8,
        'version' => 0,
        'last_edited_by' => $graph['teacher']->id,
    ]);
})->with(['finalized', 'reports-generated']);

test('mark writes resume after an assessment is unlocked', function (string $method) {
    $graph = buildGraph();
    $graph['teacher']->update(['is_admin' => true]);
    $graph['assessment']->finalize($graph['teacher']);
    $graph['assessment']->unlock($graph['teacher']);
    $uri = $method === 'POST' ? '/api/marks' : '/api/marks/'.$graph['mark']->id;

    $response = $this->withToken(tokenFor($graph['teacher']))->json($method, $uri, [
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'absent',
    ]);

    $response->assertOk()->assertJsonPath('data.mark_kind', 'absent');
    $this->assertDatabaseHas('marks', [
        'id' => $graph['mark']->id,
        'mark_kind' => 'absent',
        'score' => null,
        'version' => 1,
    ]);
})->with(['POST', 'PUT']);

test('mark writes return 422 when finalization happens after the initial mark read', function (string $method) {
    $graph = buildGraph();
    $finalized = false;
    DB::listen(function (QueryExecuted $query) use ($graph, &$finalized): void {
        if ($finalized || ! str_contains($query->sql, 'select * from "marks"')) {
            return;
        }

        $finalized = true;
        $graph['assessment']->finalize($graph['teacher']);
    });
    $uri = $method === 'POST' ? '/api/marks' : '/api/marks/'.$graph['mark']->id;

    $response = $this->withToken(tokenFor($graph['teacher']))->json($method, $uri, [
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'absent',
    ]);

    $response->assertUnprocessable()->assertInvalid([
        'assessment_id' => 'Marks cannot be changed while the assessment is finalized. Unlock it first.',
    ]);
    expect($finalized)->toBeTrue();
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'version' => 1,
    ]);
    $this->assertDatabaseHas('marks', [
        'id' => $graph['mark']->id,
        'mark_kind' => 'score',
        'score' => 8,
        'version' => 0,
    ]);
})->with(['POST', 'PUT']);

test('duplicate-cell recovery returns 422 when finalization commits before the retry', function () {
    $graph = buildGraph();
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
    $finalized = false;
    DB::listen(function (QueryExecuted $query) use ($graph, $student, &$concurrentWriteInserted): void {
        if ($concurrentWriteInserted
            || ! str_contains($query->sql, 'select * from "marks"')
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
    Event::listen(TransactionRolledBack::class, function () use ($graph, &$concurrentWriteInserted, &$finalized): void {
        if (! $concurrentWriteInserted || $finalized) {
            return;
        }

        $finalized = true;
        $graph['assessment']->finalize($graph['teacher']);
    });

    $response = $this->withToken(tokenFor($graph['teacher']))->postJson('/api/marks', [
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $student->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'score',
        'score' => 8,
    ]);

    $response->assertUnprocessable()->assertInvalid([
        'assessment_id' => 'Marks cannot be changed while the assessment is finalized. Unlock it first.',
    ]);
    expect($concurrentWriteInserted)->toBeTrue();
    expect($finalized)->toBeTrue();
    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'version' => 1,
    ]);
    $this->assertDatabaseHas('marks', [
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $student->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'empty',
        'score' => null,
        'version' => 0,
    ]);
});

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
        'version' => 2,
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
        'version' => 2,
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
        'version' => 1,
    ]);
    $this->assertDatabaseHas('reports', ['id' => $report->id, 'deleted_at' => null, 'version' => 0]);
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
        'version' => 1,
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
        'version' => 1,
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
        'version' => 1,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
});

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

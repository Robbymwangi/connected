<?php

use App\Models\Assessment;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('unlock returns 401 without an authenticated account', function () {
    $response = $this->postJson('/api/assessments/00000000-0000-4000-8000-000000000001/unlock');

    $response->assertUnauthorized();
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('a Director of Studies can unlock without reports and receives the unlocked assessment', function () {
    $graph = buildGraph();
    $graph['teacher']->update(['directs_studies' => true]);
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

test('unlock with a report records the authenticated account rather than client-supplied audit fields', function () {
    $graph = buildGraph();
    $admin = User::factory()->create([
        'institution_id' => $graph['institution']->id,
        'directs_studies' => true,
    ]);
    $comment = closeGraphTerm($graph)->comments()->sole();
    $report = issuedReport($graph);

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
    $graph['teacher']->update(['directs_studies' => true]);
    closeGraphTerm($graph);
    $report = issuedReport($graph);

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
    $graph['teacher']->update(['directs_studies' => true]);
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

test('unlock returns 403 for a teacher without the grant even when they created the assessment', function () {
    $graph = buildGraph();
    $graph['subjectModeration']->delete();
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
    $otherSchool['teacher']->update(['directs_studies' => true]);

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

test('the roster is not written through the API: the panel is its only writer', function (string $path) {
    $graph = buildGraph('School A', '-roster-write');

    $this->withToken(tokenFor($graph['teacher']))->postJson($path, ['name' => 'Anything'])->assertMethodNotAllowed();
})->with(['/api/students', '/api/classes']);

test('write policies give grading and assessments to teachers, scope assessment edits to whoever answers for them, and reserve roster records to admins', function () {
    $graph = buildGraph('School A', '-policy-matrix');
    $admin = User::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'A. Admin',
        'email' => 'admin-policy-matrix@example.com',
        'password' => 'a-hashed-password',
        'kind' => User::ADMIN,
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
    // An admin account does no academic work at all (ADR 0014): the roster is all it writes.
    expect($adminAbilities)->toBe([false, false, false, false, true, true, true, true, true, true]);
});

test('marks and assessments are written over POST /sync alone: the REST writes are gone', function () {
    $graph = buildGraph('School A', '-rest-gone');
    $token = tokenFor($graph['teacher']);

    $this->withToken($token)->postJson('/api/marks', [])->assertNotFound();
    $this->withToken($token)->putJson('/api/marks/'.$graph['mark']->id, [])->assertNotFound();
    $this->withToken($token)->postJson('/api/assessments', [])->assertMethodNotAllowed();
    $this->withToken($token)->putJson('/api/assessments/'.$graph['assessment']->id, [])->assertNotFound();
});

<?php

use App\Models\Comment;
use App\Models\Conflict;
use App\Models\Report;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

test('the creator can finalize an incomplete assessment without a teaching assignment', function () {
    $graph = buildGraph();
    $graph['teacherAssignment']->delete();
    $graph['mark']->update(['mark_kind' => 'empty', 'score' => null]);
    $this->travelTo('2026-10-05 10:00:00');

    $graph['assessment']->finalize($graph['teacher']);

    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'finalized_at' => '2026-10-05 10:00:00',
        'finalized_by' => $graph['teacher']->id,
        'version' => 1,
    ]);
    expect($graph['assessment']->status)->toBe('finalized');
});

test('an assigned class and subject teacher can finalize another teachers assessment', function () {
    $graph = buildGraph();
    $creator = User::factory()->create(['institution_id' => $graph['institution']->id]);
    $graph['assessment']->update(['created_by' => $creator->id]);

    $graph['assessment']->finalize($graph['teacher']);

    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'finalized_by' => $graph['teacher']->id,
        'version' => 2,
    ]);
});

test('a same-school teacher without the matching assignment cannot finalize even as an admin', function (bool $isAdmin) {
    $graph = buildGraph();
    $outsider = User::factory()->create([
        'institution_id' => $graph['institution']->id,
        'is_admin' => $isAdmin,
    ]);

    expect(fn () => $graph['assessment']->finalize($outsider))->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'scheduled',
        'finalized_at' => null,
        'finalized_by' => null,
        'version' => 0,
    ]);
})->with(['teacher' => [false], 'admin without lifecycle scope' => [true]]);

test('a teaching assignment must match both class and subject to allow finalize', function () {
    $graph = buildGraph();
    $creator = User::factory()->create(['institution_id' => $graph['institution']->id]);
    $otherSubject = Subject::create(['institution_id' => $graph['institution']->id, 'name' => 'Science']);
    $graph['assessment']->update(['created_by' => $creator->id, 'subject_id' => $otherSubject->id]);

    expect(fn () => $graph['assessment']->finalize($graph['teacher']))->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'scheduled',
        'version' => 1,
    ]);
});

test('an open mark conflict prevents finalization without changing the assessment', function () {
    $graph = buildGraph();
    Conflict::create([
        'institution_id' => $graph['institution']->id,
        'mark_id' => $graph['mark']->id,
        'base_version' => 0,
        'side_a' => [],
        'side_b' => [],
    ]);

    expect(fn () => $graph['assessment']->finalize($graph['teacher']))
        ->toThrow(ValidationException::class, 'An assessment with open mark conflicts cannot be finalized.');

    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'scheduled',
        'finalized_at' => null,
        'finalized_by' => null,
        'version' => 0,
    ]);
});

test('a resolved mark conflict does not prevent finalization', function () {
    $graph = buildGraph();
    Conflict::create([
        'institution_id' => $graph['institution']->id,
        'mark_id' => $graph['mark']->id,
        'base_version' => 0,
        'side_a' => [],
        'side_b' => [],
        'resolved_at' => '2026-10-05 09:00:00',
    ]);

    $graph['assessment']->finalize($graph['teacher']);

    $this->assertDatabaseHas('assessments', ['id' => $graph['assessment']->id, 'status' => 'finalized']);
});

test('finalization cannot overwrite an existing finalized cycle', function (string $status) {
    $graph = buildGraph();
    $graph['assessment']->update([
        'status' => $status,
        'finalized_at' => '2026-10-05 10:00:00',
        'finalized_by' => $graph['teacher']->id,
    ]);

    expect(fn () => $graph['assessment']->finalize($graph['teacher']))
        ->toThrow(ValidationException::class, 'The assessment must be unlocked before it can be finalized again.');

    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => $status,
        'finalized_at' => '2026-10-05 10:00:00',
        'version' => 1,
    ]);
})->with(['finalized', 'reports-generated']);

test('unlock without reports clears finalization without writing an audit note', function (?string $note) {
    $graph = buildGraph();
    $admin = $graph['teacher'];
    $admin->update(['is_admin' => true]);
    $assessment = $graph['assessment'];
    $assessment->update([
        'status' => 'finalized',
        'finalized_at' => '2026-10-05 10:00:00',
        'finalized_by' => $admin->id,
    ]);

    $assessment->unlock($admin, $note);

    $this->assertDatabaseHas('assessments', [
        'id' => $assessment->id,
        'status' => 'scheduled',
        'finalized_at' => null,
        'finalized_by' => null,
        'version' => 2,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
    $this->assertDatabaseHas('marks', [
        'id' => $graph['mark']->id,
        'mark_kind' => 'score',
        'score' => 8,
        'version' => 0,
        'deleted_at' => null,
    ]);
})->with([
    'no note' => [null],
    'unsolicited note' => ['This must not become an audit record.'],
]);

test('unlock with a report rejects a missing or blank note without changing records', function (?string $note) {
    $graph = buildGraph();
    $admin = $graph['teacher'];
    $admin->update(['is_admin' => true]);
    $assessment = $graph['assessment'];
    $assessment->update([
        'status' => 'finalized',
        'finalized_at' => '2026-10-05 10:00:00',
        'finalized_by' => $admin->id,
    ]);
    $report = Report::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $assessment->id,
        'student_id' => $graph['student']->id,
        'generated_at' => '2026-10-05 11:00:00',
        's3_key' => 'reports/student.pdf',
    ]);

    expect(fn () => $assessment->unlock($admin, $note))
        ->toThrow(ValidationException::class, 'A resolution note is required when reports exist.');

    $this->assertDatabaseHas('assessments', [
        'id' => $assessment->id,
        'status' => 'finalized',
        'finalized_at' => '2026-10-05 10:00:00',
        'finalized_by' => $admin->id,
        'version' => 1,
    ]);
    $this->assertDatabaseHas('reports', [
        'id' => $report->id,
        'deleted_at' => null,
        'version' => 0,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
})->with([
    'missing' => [null],
    'empty' => [''],
    'whitespace' => [" \t\n "],
]);

test('unlock with reports records the admin note and soft deletes the cycle while preserving marks', function (string $status) {
    $graph = buildGraph();
    $admin = User::factory()->create([
        'institution_id' => $graph['institution']->id,
        'is_admin' => true,
    ]);
    $assessment = $graph['assessment'];
    $assessment->update([
        'status' => $status,
        'finalized_at' => '2026-10-05 10:00:00',
        'finalized_by' => $graph['teacher']->id,
    ]);
    $comment = Comment::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $assessment->id,
        'student_id' => $graph['student']->id,
        'author_id' => $graph['teacher']->id,
        'body' => 'Well done.',
        'state' => 'accepted',
    ]);
    $report = Report::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $assessment->id,
        'student_id' => $graph['student']->id,
        'generated_at' => '2026-10-05 11:00:00',
        's3_key' => 'reports/student.pdf',
    ]);

    $assessment->unlock($admin, '  Correct the recorded score.  ');

    $this->assertDatabaseHas('unlock_notes', [
        'assessment_id' => $assessment->id,
        'institution_id' => $graph['institution']->id,
        'user_id' => $admin->id,
        'note' => 'Correct the recorded score.',
    ]);
    $this->assertDatabaseCount('unlock_notes', 1);
    $this->assertSoftDeleted($comment);
    $this->assertSoftDeleted($report);
    $this->assertDatabaseHas('comments', ['id' => $comment->id, 'version' => 1]);
    $this->assertDatabaseHas('reports', ['id' => $report->id, 'version' => 1]);
    $this->assertDatabaseHas('marks', [
        'id' => $graph['mark']->id,
        'score' => 8,
        'version' => 0,
        'deleted_at' => null,
    ]);
    $this->assertDatabaseHas('assessments', [
        'id' => $assessment->id,
        'status' => 'scheduled',
        'finalized_at' => null,
        'finalized_by' => null,
        'version' => 2,
    ]);
    expect($assessment->status)->toBe('scheduled');
})->with(['finalized with a partial report cycle' => ['finalized'], 'reports-generated' => ['reports-generated']]);

test('unlock without reports still soft deletes draft comments', function () {
    $graph = buildGraph();
    $graph['teacher']->update(['is_admin' => true]);
    $graph['assessment']->finalize($graph['teacher']);
    $comment = Comment::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $graph['student']->id,
        'author_id' => $graph['teacher']->id,
        'body' => 'Draft comment.',
        'state' => 'draft',
    ]);

    $graph['assessment']->unlock($graph['teacher']);

    $this->assertSoftDeleted($comment);
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('an assessment can be finalized again after unlock and historical reports do not require another note', function () {
    $graph = buildGraph();
    $graph['teacher']->update(['is_admin' => true]);
    $assessment = $graph['assessment'];
    $assessment->finalize($graph['teacher']);
    $report = Report::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $assessment->id,
        'student_id' => $graph['student']->id,
        'generated_at' => '2026-10-05 11:00:00',
        's3_key' => 'reports/old-cycle.pdf',
    ]);
    $assessment->unlock($graph['teacher'], 'Correct the recorded score.');

    $assessment->finalize($graph['teacher']);
    $assessment->unlock($graph['teacher']);

    $this->assertDatabaseHas('assessments', [
        'id' => $assessment->id,
        'status' => 'scheduled',
        'finalized_at' => null,
        'finalized_by' => null,
        'version' => 4,
    ]);
    $this->assertSoftDeleted($report);
    $this->assertDatabaseCount('unlock_notes', 1);
});

test('a non-admin creator cannot unlock a finalized assessment', function () {
    $graph = buildGraph();
    $graph['assessment']->finalize($graph['teacher']);

    expect(fn () => $graph['assessment']->unlock($graph['teacher']))->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'finalized',
        'version' => 1,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('an admin cannot unlock a scheduled assessment', function () {
    $graph = buildGraph();
    $graph['teacher']->update(['is_admin' => true]);

    expect(fn () => $graph['assessment']->unlock($graph['teacher']))
        ->toThrow(ValidationException::class, 'Only a finalized assessment can be unlocked.');

    $this->assertDatabaseHas('assessments', [
        'id' => $graph['assessment']->id,
        'status' => 'scheduled',
        'version' => 0,
    ]);
    $this->assertDatabaseCount('unlock_notes', 0);
});

test('a deactivated creator with admin capability is denied both lifecycle actions', function () {
    $graph = buildGraph();
    $graph['teacher']->update(['is_admin' => true, 'deactivated_at' => '2026-10-05 09:00:00']);

    expect(Gate::forUser($graph['teacher'])->allows('finalize', $graph['assessment']))->toBeFalse();
    expect(Gate::forUser($graph['teacher'])->allows('unlock', $graph['assessment']))->toBeFalse();
});

test('an admin from another institution is denied both lifecycle actions', function () {
    $graph = buildGraph('School A', '-a');
    $otherSchool = buildGraph('School B', '-b');
    $otherSchool['teacher']->update(['is_admin' => true]);

    expect(Gate::forUser($otherSchool['teacher'])->allows('finalize', $graph['assessment']))->toBeFalse();
    expect(Gate::forUser($otherSchool['teacher'])->allows('unlock', $graph['assessment']))->toBeFalse();
});

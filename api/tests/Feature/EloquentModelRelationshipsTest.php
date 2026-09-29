<?php

use App\Exceptions\StaleVersionException;
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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/* Ticket #40: models with relationships for all eighteen tables (the brief's
   count of thirteen is stale, per docs/spec/data-model.md's own note under
   Tables). "Done when" asks for one Pest test walking the full graph from
   institution to a mark in both directions; the rest of this file covers
   the mechanical decisions the spec's prose implies but doesn't spell out
   in Eloquent terms: the deterministic mark id, and version being
   server-managed rather than merely present. */

function buildGraph(): array
{
    $institution = Institution::create(['name' => 'Test School']);

    $teacher = User::create([
        'institution_id' => $institution->id,
        'name' => 'T. Teacher',
        'email' => 'teacher@example.com',
        'password' => 'a-hashed-password',
    ]);

    $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Maths']);

    $criterion = Criterion::create([
        'institution_id' => $institution->id,
        'subject_id' => $subject->id,
        'name' => 'Accuracy',
        'max_score' => 10,
    ]);

    $class = SchoolClass::create([
        'institution_id' => $institution->id,
        'grade' => '4',
        'stream' => 'West',
        'class_teacher_id' => $teacher->id,
    ]);

    $classSubject = ClassSubject::create([
        'institution_id' => $institution->id,
        'class_id' => $class->id,
        'subject_id' => $subject->id,
    ]);

    $teacherAssignment = TeacherAssignment::create([
        'institution_id' => $institution->id,
        'user_id' => $teacher->id,
        'class_id' => $class->id,
        'subject_id' => $subject->id,
    ]);

    $subjectModeration = SubjectModeration::create([
        'institution_id' => $institution->id,
        'user_id' => $teacher->id,
        'subject_id' => $subject->id,
    ]);

    $student = Student::create([
        'institution_id' => $institution->id,
        'name' => 'S. Student',
        'gender' => 'F',
        'dob' => '2015-01-01',
    ]);

    $enrolment = Enrolment::create([
        'institution_id' => $institution->id,
        'student_id' => $student->id,
        'class_id' => $class->id,
        'year' => 2026,
    ]);

    $assessment = Assessment::create([
        'institution_id' => $institution->id,
        'class_id' => $class->id,
        'subject_id' => $subject->id,
        'name' => 'CAT 1',
        'term' => 1,
        'year' => 2026,
        'date' => '2026-02-01',
        'status' => 'scheduled',
        'created_by' => $teacher->id,
    ]);

    $mark = Mark::create([
        'institution_id' => $institution->id,
        'assessment_id' => $assessment->id,
        'student_id' => $student->id,
        'criterion_id' => $criterion->id,
        'mark_kind' => 'score',
        'score' => 8,
        'last_edited_by' => $teacher->id,
    ]);

    return compact(
        'institution', 'teacher', 'subject', 'criterion', 'class', 'classSubject',
        'teacherAssignment', 'subjectModeration', 'student', 'enrolment', 'assessment', 'mark',
    );
}

test('creates a full object graph from institution down to a mark and traverses it in both directions', function () {
    $g = buildGraph();

    // Forward: institution -> ... -> mark.
    expect($g['institution']->users)->toHaveCount(1);
    expect($g['institution']->users->first()->is($g['teacher']))->toBeTrue();
    expect($g['institution']->subjects->first()->criteria->first()->is($g['criterion']))->toBeTrue();
    expect($g['institution']->classes->first()->is($g['class']))->toBeTrue();
    expect($g['class']->classSubjects->first()->subject->is($g['subject']))->toBeTrue();
    expect($g['class']->teacherAssignments->first()->teacher->is($g['teacher']))->toBeTrue();
    expect($g['institution']->students->first()->enrolments->first()->schoolClass->is($g['class']))->toBeTrue();
    expect($g['assessment']->marks->first()->is($g['mark']))->toBeTrue();

    // Backward: mark -> ... -> institution.
    expect($g['mark']->criterion->subject->institution->is($g['institution']))->toBeTrue();
    expect($g['mark']->assessment->schoolClass->classTeacher->is($g['teacher']))->toBeTrue();
    expect($g['mark']->student->is($g['student']))->toBeTrue();
    expect($g['mark']->lastEditedBy->is($g['teacher']))->toBeTrue();
    expect($g['mark']->assessment->subject->is($g['subject']))->toBeTrue();
    expect($g['mark']->assessment->createdBy->is($g['teacher']))->toBeTrue();

    // The grant tables sit beside the graph, reachable from either end.
    expect($g['teacher']->subjectModerations->first()->subject->is($g['subject']))->toBeTrue();
    expect($g['teacher']->teacherAssignments->first()->schoolClass->is($g['class']))->toBeTrue();
});

test('a mark id is a deterministic UUIDv5 of its identity triple, not a fresh UUID per row', function () {
    $g = buildGraph();

    $expected = Uuid::uuid5(
        Mark::MARK_UUID_NAMESPACE,
        "{$g['assessment']->id}:{$g['student']->id}:{$g['criterion']->id}",
    )->toString();

    expect($g['mark']->id)->toBe($expected);
});

test('a mark id supplied for the wrong identity triple is rejected, not silently accepted or replaced', function () {
    $g = buildGraph();

    $otherCriterion = Criterion::create([
        'institution_id' => $g['institution']->id,
        'subject_id' => $g['subject']->id,
        'name' => 'Presentation',
        'max_score' => 10,
    ]);

    expect(fn () => Mark::create([
        'id' => $g['mark']->id, // belongs to $g['criterion'], not $otherCriterion
        'institution_id' => $g['institution']->id,
        'assessment_id' => $g['assessment']->id,
        'student_id' => $g['student']->id,
        'criterion_id' => $otherCriterion->id,
        'mark_kind' => 'score',
        'score' => 5,
        'last_edited_by' => $g['teacher']->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('class_subjects is only ever written through ClassSubject, never a pivot writer', function () {
    $g = buildGraph();

    expect(method_exists(SchoolClass::class, 'subjects'))->toBeFalse();
    expect(method_exists(Subject::class, 'classes'))->toBeFalse();

    // The safe read path still works and returns full ClassSubject rows,
    // with their own id, institution_id, version, and soft delete intact.
    $relation = $g['class']->classSubjects->first();
    expect($relation->is($g['classSubject']))->toBeTrue();
    expect($relation->version)->toBe(0);
});

test('two independent creates for the same mark identity triple collide on id, not on producing two rows', function () {
    $g = buildGraph();

    // The failing insert must throw from inside DB::transaction() itself, not
    // from inside an expect(...)->toThrow() that DB::transaction() wraps:
    // Postgres aborts the underlying transaction the moment the duplicate
    // key error happens, and only DB::transaction()'s own catch block issues
    // the ROLLBACK TO SAVEPOINT that clears that aborted state. Swallowing
    // the exception one level too early leaves every later query in this
    // test failing with "current transaction is aborted".
    expect(fn () => DB::transaction(fn () => Mark::create([
        'institution_id' => $g['institution']->id,
        'assessment_id' => $g['assessment']->id,
        'student_id' => $g['student']->id,
        'criterion_id' => $g['criterion']->id,
        'mark_kind' => 'score',
        'score' => 5,
        'last_edited_by' => $g['teacher']->id,
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(Mark::count())->toBe(1);
});

test('a client-supplied id is kept as given, never overridden', function () {
    $g = buildGraph();

    $client = Student::create([
        'id' => '01991827-0000-7000-8000-000000000001',
        'institution_id' => $g['institution']->id,
        'name' => 'Offline Student',
        'gender' => 'M',
        'dob' => '2016-01-01',
    ]);

    expect($client->id)->toBe('01991827-0000-7000-8000-000000000001');
});

test('version starts at 0, increments server-side on update and on soft delete, and is never mass-assignable', function () {
    $g = buildGraph();
    $subject = $g['subject'];

    expect($subject->version)->toBe(0);

    $subject->name = 'Mathematics';
    $subject->save();
    expect($subject->version)->toBe(1);

    $subject->delete();
    expect($subject->version)->toBe(1 + 1);
    expect(Subject::withTrashed()->find($subject->id)->version)->toBe(2);

    $subject->fill(['version' => 999, 'name' => 'Ignored version']);
    expect($subject->version)->toBe(2);
    expect($subject->name)->toBe('Ignored version');
});

test('a no-op save and a bare touch do not bump version', function () {
    $g = buildGraph();
    $subject = $g['subject'];

    $subject->save();
    expect($subject->fresh()->version)->toBe(0);

    $subject->touch();
    expect($subject->fresh()->version)->toBe(0);
    expect($subject->fresh()->updated_at)->not->toBeNull();
});

test('a stale update loses the race instead of silently overwriting the winner', function () {
    $g = buildGraph();

    // Two processes independently loading the same row.
    $writerA = Subject::find($g['subject']->id);
    $writerB = Subject::find($g['subject']->id);

    $writerA->name = 'Mathematics (A)';
    $writerA->save();
    expect($writerA->version)->toBe(1);

    $writerB->name = 'Mathematics (B)';
    expect(fn () => $writerB->save())->toThrow(StaleVersionException::class);

    // A's write is intact; B's was rejected, not merged or silently lost.
    $fromDb = Subject::find($g['subject']->id);
    expect($fromDb->name)->toBe('Mathematics (A)');
    expect($fromDb->version)->toBe(1);
});

test('a stale delete loses the race the same way an update does', function () {
    $g = buildGraph();

    $writerA = Subject::find($g['subject']->id);
    $writerB = Subject::find($g['subject']->id);

    $writerA->name = 'Mathematics';
    $writerA->save();

    expect(fn () => $writerB->delete())->toThrow(StaleVersionException::class);
    expect(Subject::find($g['subject']->id))->not->toBeNull();
});

test('institutions and unlock_notes carry no version and no soft delete, the two non-synchronisable models', function () {
    expect(class_uses_recursive(Institution::class))->not->toHaveKey(SoftDeletes::class);
    expect(class_uses_recursive(UnlockNote::class))->not->toHaveKey(SoftDeletes::class);

    $institution = Institution::create(['name' => 'Unversioned School']);
    expect($institution->version ?? null)->toBeNull();
});

test('the remaining tables (conflicts, results, comments, reports, notifications, unlock notes) relate correctly', function () {
    $g = buildGraph();

    $conflict = Conflict::create([
        'institution_id' => $g['institution']->id,
        'mark_id' => $g['mark']->id,
        'base_version' => 0,
        'side_a' => ['editId' => 'e1', 'userId' => $g['teacher']->id, 'markKind' => 'score', 'score' => 8, 'at' => now()->toIso8601String()],
        'side_b' => ['editId' => 'e2', 'userId' => $g['teacher']->id, 'markKind' => 'score', 'score' => 6, 'at' => now()->toIso8601String()],
    ]);
    expect($conflict->mark->is($g['mark']))->toBeTrue();
    expect($conflict->side_a)->toBeArray()->toHaveKey('editId', 'e1');

    $result = Result::create([
        'institution_id' => $g['institution']->id,
        'assessment_id' => $g['assessment']->id,
        'student_id' => $g['student']->id,
        'total' => 8,
        'max' => 10,
        'level' => 'ME',
    ]);
    expect($result->assessment->is($g['assessment']))->toBeTrue();
    expect($result->student->is($g['student']))->toBeTrue();

    $comment = Comment::create([
        'institution_id' => $g['institution']->id,
        'assessment_id' => $g['assessment']->id,
        'student_id' => $g['student']->id,
        'author_id' => $g['teacher']->id,
        'body' => 'Good progress this term.',
        'state' => 'draft',
    ]);
    expect($comment->author->is($g['teacher']))->toBeTrue();

    $report = Report::create([
        'institution_id' => $g['institution']->id,
        'assessment_id' => $g['assessment']->id,
        'student_id' => $g['student']->id,
        'generated_at' => now(),
        's3_key' => 'reports/test.pdf',
    ]);
    expect($report->student->is($g['student']))->toBeTrue();

    $notification = Notification::create([
        'institution_id' => $g['institution']->id,
        'user_id' => $g['teacher']->id,
        'kind' => 'sync-conflict',
        'tone' => 'warning',
        'title' => 'Conflict raised',
        'body' => 'A mark was edited on two devices.',
        'unread' => true,
    ]);
    expect($notification->unread)->toBeTrue();
    expect($notification->user->is($g['teacher']))->toBeTrue();

    $unlockNote = UnlockNote::create([
        'institution_id' => $g['institution']->id,
        'assessment_id' => $g['assessment']->id,
        'user_id' => $g['teacher']->id,
        'note' => 'Reopened for a correction.',
    ]);
    expect($unlockNote->assessment->is($g['assessment']))->toBeTrue();
    expect($unlockNote->user->is($g['teacher']))->toBeTrue();
});

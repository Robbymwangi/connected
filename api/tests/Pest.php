<?php

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Institution;
use App\Models\Mark;
use App\Models\Notification;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectModeration;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/* Shared across EloquentModelRelationshipsTest (#40) and InstitutionScopeTest
   (#41): a full object graph from one institution down to one mark. $suffix
   keeps a second call's rows (a second institution, a different teacher
   email, since users.email is globally unique) from colliding with the
   first's. */
/**
 * @return array{
 *     institution: Institution, teacher: User, subject: Subject, criterion: Criterion,
 *     class: SchoolClass, classSubject: ClassSubject, teacherAssignment: TeacherAssignment,
 *     subjectModeration: SubjectModeration, student: Student, enrolment: Enrolment,
 *     assessment: Assessment, mark: Mark,
 * }
 */
/* A bearer token for a model-scoped protected-route test, with the sync
   ability every real device token carries (docs/spec/access-model.md,
   Tokens). */
function tokenFor(User $user): string
{
    return $user->createToken('device', ['sync'])->plainTextToken;
}

function buildGraph(string $institutionName = 'Test School', string $suffix = ''): array
{
    $institution = Institution::create(['name' => $institutionName]);

    $teacher = User::create([
        'institution_id' => $institution->id,
        'name' => 'T. Teacher',
        'email' => "teacher{$suffix}@example.com",
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

/* POST /sync helpers (3.2a). Pest test-file functions are global, so they live
   here once; a second definition of any of these names in a test file is fatal. */
function pushEntries(mixed $test, string $token, array $entries)
{
    return $test->withToken($token)->postJson('/api/sync', ['entries' => $entries]);
}

/** One outbox entry, with a fresh UUIDv7 mutation id unless one is given. */
function pushEntry(string $table, string $recordId, int $base, array $fields, ?string $id = null, ?string $at = null): array
{
    return array_filter([
        'id' => $id ?? Str::uuid7()->toString(),
        'table' => $table,
        'recordId' => $recordId,
        'baseVersion' => $base,
        'fields' => $fields,
        'at' => $at,
    ], fn ($value) => $value !== null);
}

/** The fields of a valid assessment create against a graph from buildGraph. */
function assessmentFields(array $graph, array $overrides = []): array
{
    return array_merge([
        'classId' => $graph['class']->id,
        'subjectId' => $graph['subject']->id,
        'name' => 'CAT 2',
        'term' => 2,
        'year' => 2026,
        'date' => '2026-08-18',
    ], $overrides);
}

/**
 * A second user in the graph's institution; optionally an admin, optionally assigned to the graph's class and subject.
 */
function makeColleague(array $graph, string $email, bool $admin = false, bool $assigned = false): User
{
    $user = User::create([
        'institution_id' => $graph['institution']->id,
        'name' => 'A. Colleague',
        'email' => $email,
        'password' => 'a-hashed-password',
        'is_admin' => $admin,
    ]);

    if ($assigned) {
        TeacherAssignment::create([
            'institution_id' => $graph['institution']->id,
            'user_id' => $user->id,
            'class_id' => $graph['class']->id,
            'subject_id' => $graph['subject']->id,
        ]);
    }

    return $user;
}

/** The decoded `results` of a POST /sync response that must have been a 200. */
function postedResults(mixed $response): array
{
    return $response->assertOk()->json('results');
}

/** The deterministic id of a mark cell, as a device computes it. */
function markIdFor(string $assessmentId, string $studentId, string $criterionId): string
{
    return (new Mark([
        'assessment_id' => $assessmentId,
        'student_id' => $studentId,
        'criterion_id' => $criterionId,
    ]))->newUniqueId();
}

/* Mark-cell helpers shared by the POST /sync mark and conflict tests (3.2a, 3.2b). */
function newCriterion(array $graph, string $name = 'Reasoning', int $max = 10): Criterion
{
    return Criterion::create([
        'institution_id' => $graph['institution']->id,
        'subject_id' => $graph['subject']->id,
        'name' => $name,
        'max_score' => $max,
    ]);
}

function enrolledStudent(array $graph, string $name): Student
{
    $student = Student::create([
        'institution_id' => $graph['institution']->id,
        'name' => $name,
        'gender' => 'F',
        'dob' => '2015-03-01',
    ]);
    Enrolment::create([
        'institution_id' => $graph['institution']->id,
        'student_id' => $student->id,
        'class_id' => $graph['class']->id,
        'year' => $graph['assessment']->year,
    ]);

    return $student;
}

/** A create entry for a new cell, with the deterministic id a device would compute. */
function markCreate(array $graph, Student $student, Criterion $criterion, array $cell = ['markKind' => 'score', 'score' => 7]): array
{
    $triple = ['assessmentId' => $graph['assessment']->id, 'studentId' => $student->id, 'criterionId' => $criterion->id];

    return pushEntry('marks', markIdFor($triple['assessmentId'], $triple['studentId'], $triple['criterionId']), 0, [...$triple, ...$cell]);
}

function markUpdate(array $graph, array $fields, int $base = 1): array
{
    return pushEntry('marks', $graph['mark']->id, $base, $fields);
}

/** A notification the server wrote for a user, unread, at version 1. */
function notificationFor(User $user, array $overrides = []): Notification
{
    return Notification::create(array_merge([
        'institution_id' => $user->institution_id,
        'user_id' => $user->id,
        'kind' => 'sync-conflict',
        'tone' => 'warning',
        'title' => 'Mark conflict to settle',
        'body' => 'Two edits to the same mark disagree.',
        'unread' => true,
    ], $overrides));
}

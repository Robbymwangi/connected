<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/* Schema assertions from docs/spec/data-model.md (#34), the authoritative source
   for this ticket. Table-by-table column checks are mechanical; the constraint
   tests below them are the ones worth failing on purpose. */

$tables = [
    'institutions' => ['id', 'name'],
    'users' => ['id', 'institution_id', 'name', 'email', 'password', 'is_admin', 'deactivated_at', 'version', 'deleted_at'],
    'subjects' => ['id', 'institution_id', 'name', 'version', 'deleted_at'],
    'criteria' => ['id', 'institution_id', 'subject_id', 'name', 'max_score', 'version', 'deleted_at'],
    'classes' => ['id', 'institution_id', 'grade', 'stream', 'class_teacher_id', 'version', 'deleted_at'],
    'class_subjects' => ['id', 'institution_id', 'class_id', 'subject_id', 'version', 'deleted_at'],
    'teacher_assignments' => ['id', 'institution_id', 'user_id', 'class_id', 'subject_id', 'version', 'deleted_at'],
    'subject_moderations' => ['id', 'institution_id', 'user_id', 'subject_id', 'version', 'deleted_at'],
    'students' => ['id', 'institution_id', 'name', 'gender', 'dob', 'version', 'deleted_at'],
    'enrolments' => ['id', 'institution_id', 'student_id', 'class_id', 'year', 'version', 'deleted_at'],
    'assessments' => ['id', 'institution_id', 'class_id', 'subject_id', 'name', 'term', 'year', 'date', 'status', 'created_by', 'finalized_at', 'finalized_by', 'version', 'deleted_at'],
    'marks' => ['id', 'institution_id', 'assessment_id', 'student_id', 'criterion_id', 'mark_kind', 'score', 'last_edited_by', 'version', 'deleted_at'],
    'conflicts' => ['id', 'institution_id', 'mark_id', 'base_version', 'side_a', 'side_b', 'proposals', 'referral', 'resolution', 'resolved_at', 'version', 'deleted_at'],
    'results' => ['id', 'institution_id', 'assessment_id', 'student_id', 'total', 'max', 'level', 'version', 'deleted_at'],
    'comments' => ['id', 'institution_id', 'assessment_id', 'student_id', 'author_id', 'body', 'state', 'version', 'deleted_at'],
    'reports' => ['id', 'institution_id', 'assessment_id', 'student_id', 'generated_at', 's3_key', 'version', 'deleted_at'],
    'notifications' => ['id', 'institution_id', 'user_id', 'assessment_id', 'kind', 'tone', 'title', 'body', 'unread', 'version', 'deleted_at'],
    'unlock_notes' => ['id', 'institution_id', 'assessment_id', 'user_id', 'note', 'created_at'],
];

foreach ($tables as $table => $columns) {
    test("{$table} table has its data-model columns")
        ->expect(fn () => Schema::hasColumns($table, $columns))
        ->toBeTrue();
}

test('institutions and unlock_notes have no version or deleted_at, the two non-synchronisable tables', function () {
    expect(Schema::hasColumn('institutions', 'version'))->toBeFalse();
    expect(Schema::hasColumn('institutions', 'deleted_at'))->toBeFalse();
    expect(Schema::hasColumn('unlock_notes', 'version'))->toBeFalse();
    expect(Schema::hasColumn('unlock_notes', 'deleted_at'))->toBeFalse();
});

test('every domain table other than institutions carries institution_id', function () use ($tables) {
    foreach (array_keys($tables) as $table) {
        if ($table === 'institutions') {
            continue;
        }
        expect(Schema::hasColumn($table, 'institution_id'))->toBeTrue();
    }
});

/* Row helpers: fixture ids kept as variables so the constraint tests below read
   as a single chain of related inserts, one row per table it depends on. */
function seedInstitution(): string
{
    $id = Str::uuid7()->toString();
    DB::table('institutions')->insert(['id' => $id, 'name' => 'Test School', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

test('marks.score must be null unless mark_kind is score', function () {
    $institutionId = seedInstitution();
    $subjectId = Str::uuid7()->toString();
    DB::table('subjects')->insert(['id' => $subjectId, 'institution_id' => $institutionId, 'name' => 'English', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $criterionId = Str::uuid7()->toString();
    DB::table('criteria')->insert(['id' => $criterionId, 'institution_id' => $institutionId, 'subject_id' => $subjectId, 'name' => 'Comprehension', 'max_score' => 20, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $classId = Str::uuid7()->toString();
    DB::table('classes')->insert(['id' => $classId, 'institution_id' => $institutionId, 'grade' => '4', 'stream' => '4W', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $userId = Str::uuid7()->toString();
    DB::table('users')->insert(['id' => $userId, 'institution_id' => $institutionId, 'name' => 'Ms Akinyi', 'email' => 'akinyi@example.com', 'password' => 'x', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $assessmentId = Str::uuid7()->toString();
    DB::table('assessments')->insert(['id' => $assessmentId, 'institution_id' => $institutionId, 'class_id' => $classId, 'subject_id' => $subjectId, 'name' => 'CAT 1', 'term' => 1, 'year' => 2026, 'date' => '2026-02-01', 'status' => 'scheduled', 'created_by' => $userId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $studentId = Str::uuid7()->toString();
    DB::table('students')->insert(['id' => $studentId, 'institution_id' => $institutionId, 'name' => 'A Student', 'gender' => 'F', 'dob' => '2015-01-01', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    // score kind with a null score: rejected.
    expect(fn () => DB::transaction(fn () => DB::table('marks')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId,
        'student_id' => $studentId, 'criterion_id' => $criterionId, 'mark_kind' => 'score', 'score' => null,
        'last_edited_by' => $userId, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class);

    // absent kind with a non-null score: rejected, absence is never a number.
    expect(fn () => DB::transaction(fn () => DB::table('marks')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId,
        'student_id' => $studentId, 'criterion_id' => $criterionId, 'mark_kind' => 'absent', 'score' => 10,
        'last_edited_by' => $userId, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class);

    // absent kind with a null score: the correct shape for absence, accepted.
    DB::table('marks')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId,
        'student_id' => $studentId, 'criterion_id' => $criterionId, 'mark_kind' => 'absent', 'score' => null,
        'last_edited_by' => $userId, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(DB::table('marks')->count())->toBe(1);
});

test('enrolments are unique per student per year', function () {
    $institutionId = seedInstitution();
    $classId = Str::uuid7()->toString();
    DB::table('classes')->insert(['id' => $classId, 'institution_id' => $institutionId, 'grade' => '4', 'stream' => '4W', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $studentId = Str::uuid7()->toString();
    DB::table('students')->insert(['id' => $studentId, 'institution_id' => $institutionId, 'name' => 'A Student', 'gender' => 'F', 'dob' => '2015-01-01', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    DB::table('enrolments')->insert(['id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'student_id' => $studentId, 'class_id' => $classId, 'year' => 2026, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('enrolments')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'student_id' => $studentId,
        'class_id' => $classId, 'year' => 2026, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class);
});

test('class_subjects is unique per class per subject', function () {
    $institutionId = seedInstitution();
    $classId = Str::uuid7()->toString();
    DB::table('classes')->insert(['id' => $classId, 'institution_id' => $institutionId, 'grade' => '4', 'stream' => '4W', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $subjectId = Str::uuid7()->toString();
    DB::table('subjects')->insert(['id' => $subjectId, 'institution_id' => $institutionId, 'name' => 'English', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    DB::table('class_subjects')->insert(['id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'class_id' => $classId, 'subject_id' => $subjectId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('class_subjects')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'class_id' => $classId,
        'subject_id' => $subjectId, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class);
});

test('sessions.user_id is uuid, matching users.id', function () {
    // Regression: the skeleton migration's default is foreignId, a bigint
    // that could never actually hold a users.id value.
    $column = DB::selectOne(
        "select data_type from information_schema.columns where table_name = 'sessions' and column_name = 'user_id'"
    );
    expect($column->data_type)->toBe('uuid');
});

test('numeric columns reject the out-of-range values unsignedInteger implies but does not enforce on Postgres', function () {
    // Postgres has no native unsigned integer type, so unsignedInteger() and
    // its siblings are silently plain integers here; every check below would
    // pass its insert through uncaught without the explicit constraints this
    // migration set adds for each.
    $institutionId = seedInstitution();
    $subjectId = Str::uuid7()->toString();
    DB::table('subjects')->insert(['id' => $subjectId, 'institution_id' => $institutionId, 'name' => 'English', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('criteria')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'subject_id' => $subjectId,
        'name' => 'x', 'max_score' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class, null, 'criteria.max_score <= 0 should be rejected');

    $classId = Str::uuid7()->toString();
    DB::table('classes')->insert(['id' => $classId, 'institution_id' => $institutionId, 'grade' => '4', 'stream' => '4W', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $userId = Str::uuid7()->toString();
    DB::table('users')->insert(['id' => $userId, 'institution_id' => $institutionId, 'name' => 'x', 'email' => 'range@example.com', 'password' => 'x', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('assessments')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'class_id' => $classId, 'subject_id' => $subjectId,
        'name' => 'x', 'term' => 4, 'year' => 2026, 'date' => '2026-02-01', 'status' => 'scheduled', 'created_by' => $userId,
        'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class, null, 'assessments.term outside 1 to 3 should be rejected');

    $criterionId = Str::uuid7()->toString();
    DB::table('criteria')->insert(['id' => $criterionId, 'institution_id' => $institutionId, 'subject_id' => $subjectId, 'name' => 'x', 'max_score' => 20, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $assessmentId = Str::uuid7()->toString();
    DB::table('assessments')->insert(['id' => $assessmentId, 'institution_id' => $institutionId, 'class_id' => $classId, 'subject_id' => $subjectId, 'name' => 'x', 'term' => 1, 'year' => 2026, 'date' => '2026-02-01', 'status' => 'scheduled', 'created_by' => $userId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $studentId = Str::uuid7()->toString();
    DB::table('students')->insert(['id' => $studentId, 'institution_id' => $institutionId, 'name' => 'x', 'gender' => 'F', 'dob' => '2015-01-01', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('marks')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId,
        'student_id' => $studentId, 'criterion_id' => $criterionId, 'mark_kind' => 'score', 'score' => -1,
        'last_edited_by' => $userId, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class, null, 'marks.score negative should be rejected');

    expect(fn () => DB::transaction(fn () => DB::table('results')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId,
        'student_id' => $studentId, 'total' => -1, 'max' => 20, 'level' => 'BE', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class, null, 'results.total negative should be rejected');

    expect(fn () => DB::transaction(fn () => DB::table('enrolments')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'student_id' => $studentId,
        'class_id' => $classId, 'year' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class, null, 'enrolments.year not positive should be rejected');
});

test('a soft-deleted row does not block re-creating the same combination', function () {
    // The regression this migration set guards against: a plain unique
    // constraint would keep counting a soft-deleted row, so revoking a
    // teacher assignment and then re-granting it would collide with its
    // own history instead of succeeding with a fresh id.
    $institutionId = seedInstitution();
    $classId = Str::uuid7()->toString();
    DB::table('classes')->insert(['id' => $classId, 'institution_id' => $institutionId, 'grade' => '4', 'stream' => '4W', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $subjectId = Str::uuid7()->toString();
    DB::table('subjects')->insert(['id' => $subjectId, 'institution_id' => $institutionId, 'name' => 'English', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $userId = Str::uuid7()->toString();
    DB::table('users')->insert(['id' => $userId, 'institution_id' => $institutionId, 'name' => 'Mr Doe', 'email' => 'doe@example.com', 'password' => 'x', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $firstGrantId = Str::uuid7()->toString();
    DB::table('teacher_assignments')->insert(['id' => $firstGrantId, 'institution_id' => $institutionId, 'user_id' => $userId, 'class_id' => $classId, 'subject_id' => $subjectId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    DB::table('teacher_assignments')->where('id', $firstGrantId)->update(['deleted_at' => now()]);

    // A fresh id for the same (user, class, subject): must succeed now that
    // the earlier grant is soft-deleted, not collide with it.
    DB::table('teacher_assignments')->insert(['id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'user_id' => $userId, 'class_id' => $classId, 'subject_id' => $subjectId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('teacher_assignments')->count())->toBe(2);
    expect(DB::table('teacher_assignments')->whereNull('deleted_at')->count())->toBe(1);
});

test('results, comments, and reports are each unique per assessment per student', function () {
    $institutionId = seedInstitution();
    $subjectId = Str::uuid7()->toString();
    DB::table('subjects')->insert(['id' => $subjectId, 'institution_id' => $institutionId, 'name' => 'English', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $classId = Str::uuid7()->toString();
    DB::table('classes')->insert(['id' => $classId, 'institution_id' => $institutionId, 'grade' => '4', 'stream' => '4W', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $userId = Str::uuid7()->toString();
    DB::table('users')->insert(['id' => $userId, 'institution_id' => $institutionId, 'name' => 'Ms Akinyi', 'email' => 'akinyi2@example.com', 'password' => 'x', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $assessmentId = Str::uuid7()->toString();
    DB::table('assessments')->insert(['id' => $assessmentId, 'institution_id' => $institutionId, 'class_id' => $classId, 'subject_id' => $subjectId, 'name' => 'CAT 1', 'term' => 1, 'year' => 2026, 'date' => '2026-02-01', 'status' => 'scheduled', 'created_by' => $userId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $studentId = Str::uuid7()->toString();
    DB::table('students')->insert(['id' => $studentId, 'institution_id' => $institutionId, 'name' => 'A Student', 'gender' => 'F', 'dob' => '2015-01-01', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    DB::table('results')->insert(['id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId, 'student_id' => $studentId, 'total' => 15, 'max' => 20, 'level' => 'ME', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => DB::transaction(fn () => DB::table('results')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId,
        'student_id' => $studentId, 'total' => 10, 'max' => 20, 'level' => 'AE', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class);

    DB::table('comments')->insert(['id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId, 'student_id' => $studentId, 'author_id' => $userId, 'body' => 'Good work.', 'state' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => DB::transaction(fn () => DB::table('comments')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId,
        'student_id' => $studentId, 'author_id' => $userId, 'body' => 'Again.', 'state' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class);

    DB::table('reports')->insert(['id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId, 'student_id' => $studentId, 'generated_at' => now(), 's3_key' => 'reports/one.pdf', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => DB::transaction(fn () => DB::table('reports')->insert([
        'id' => Str::uuid7()->toString(), 'institution_id' => $institutionId, 'assessment_id' => $assessmentId,
        'student_id' => $studentId, 'generated_at' => now(), 's3_key' => 'reports/two.pdf', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class);
});

test('every primary key column is uuid, not an auto-incrementing integer', function () use ($tables) {
    foreach (array_keys($tables) as $table) {
        $column = DB::selectOne(
            "select data_type from information_schema.columns where table_name = ? and column_name = 'id'",
            [$table]
        );
        expect($column->data_type)->toBe('uuid', "{$table}.id should be uuid");
    }
});

test('migrate:fresh then migrate:rollback returns the database to empty', function () use ($tables) {
    Artisan::call('migrate:fresh', ['--force' => true]);
    expect(Schema::hasTable('marks'))->toBeTrue();

    Artisan::call('migrate:rollback', ['--force' => true, '--step' => 100]);
    foreach (array_keys($tables) as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} should be gone after a full rollback");
    }

    // Leave the database migrated again so RefreshDatabase isn't left confused
    // for whatever test runs after this one in the same process.
    Artisan::call('migrate', ['--force' => true]);
});

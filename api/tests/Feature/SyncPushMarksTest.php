<?php

use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* 3.2a, C5: marks over POST /sync. Grading is unrestricted by who, so what these
   tests hold is everything else: the deterministic id, institution-scoped references,
   enrolment, the criterion's subject and maximum, the finalized lock, the
   markKind/score pair, and the version rules on a real cell. The shared decisions
   (replay, envelope, forbidden) are in SyncPushTest. docs/spec/sync-protocol.md. */

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

test('a non-admin teacher with no assignment to the class and subject creates and updates a mark, and both are accepted', function () {
    $g = buildGraph('School A', '-mk-unassigned');
    $teacher = makeColleague($g, 'unassigned-mk@example.com');
    $criterion = newCriterion($g);
    $create = markCreate($g, $g['student'], $criterion);
    $update = markUpdate($g, ['score' => 9]);

    $results = postedResults(pushEntries($this, tokenFor($teacher), [$create, $update]));

    expect($results)->toBe([
        ['id' => $create['id'], 'status' => 'accepted', 'version' => 1],
        ['id' => $update['id'], 'status' => 'accepted', 'version' => 2],
    ]);

    $created = DB::table('marks')->where('id', $create['recordId'])->first();
    expect($created->last_edited_by)->toBe($teacher->id);
    expect($created->institution_id)->toBe($g['institution']->id);
    expect(DB::table('marks')->where('id', $g['mark']->id)->first())->toMatchArray(['score' => 9, 'last_edited_by' => $teacher->id, 'version' => 2]);
    expect(DB::table('sync_changes')->where('record_id', $create['recordId'])->count())->toBe(1);
    expect(DB::table('sync_mutations')->whereIn('id', [$create['id'], $update['id']])->where('status', 'accepted')->count())->toBe(2);
});

test('thirty entries with one stale entry in the middle: twenty-nine persisted, one conflict, results in order', function () {
    $g = buildGraph('School A', '-mk-thirty');
    $criterion = newCriterion($g);
    $entries = [];

    foreach (range(1, 29) as $n) {
        $entries[] = markCreate($g, enrolledStudent($g, "Pupil {$n}"), $criterion, ['markKind' => 'score', 'score' => $n % 10]);
    }

    // The graph's own cell, sent as a create at base 0: a known id, so a conflict.
    $stale = pushEntry('marks', $g['mark']->id, 0, [
        'assessmentId' => $g['assessment']->id, 'studentId' => $g['student']->id, 'criterionId' => $g['criterion']->id,
        'markKind' => 'score', 'score' => 3,
    ]);
    array_splice($entries, 14, 0, [$stale]);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), $entries));

    expect($results)->toHaveCount(30);
    expect(array_column($results, 'id'))->toBe(array_column($entries, 'id'));
    expect($results[14]['status'])->toBe('conflict');
    expect(collect($results)->except(14)->pluck('status')->unique()->all())->toBe(['accepted']);
    expect(DB::table('marks')->where('criterion_id', $criterion->id)->count())->toBe(29);
    expect(DB::table('marks')->where('id', $g['mark']->id)->value('score'))->toBe(8);
});

test('a reference from another institution is invalid', function (string $reference) {
    $a = buildGraph('School A', '-mk-xinst-a');
    $b = buildGraph('School B', '-mk-xinst-b');
    $criterion = newCriterion($a);
    $triple = ['assessmentId' => $a['assessment']->id, 'studentId' => $a['student']->id, 'criterionId' => $criterion->id];
    $triple[$reference] = ['assessmentId' => $b['assessment']->id, 'studentId' => $b['student']->id, 'criterionId' => $b['criterion']->id][$reference];
    $entry = pushEntry('marks', markIdFor(...array_values($triple)), 0, [...$triple, 'markKind' => 'score', 'score' => 5]);

    $result = postedResults(pushEntries($this, tokenFor($a['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toBe("{$reference} does not resolve");
    expect(DB::table('marks')->where('id', $entry['recordId'])->exists())->toBeFalse();
})->with(['assessmentId', 'studentId', 'criterionId']);

test('a student not enrolled in the assessment class and year is invalid', function () {
    $g = buildGraph('School A', '-mk-unenrolled');
    $stranger = Student::create(['institution_id' => $g['institution']->id, 'name' => 'Not enrolled', 'gender' => 'M', 'dob' => '2015-04-01']);
    $entry = markCreate($g, $stranger, newCriterion($g));

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'The student must be enrolled in the assessment class and year.']);
});

test('a criterion of another subject is invalid', function () {
    $g = buildGraph('School A', '-mk-other-subject');
    $science = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Science']);
    $foreign = Criterion::create(['institution_id' => $g['institution']->id, 'subject_id' => $science->id, 'name' => 'Method', 'max_score' => 10]);
    $entry = markCreate($g, $g['student'], $foreign);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'The criterion must belong to the assessment subject.']);
});

test('a finalized or reports-generated assessment makes a mark edit invalid, even when its base is stale', function (string $status) {
    $g = buildGraph('School A', '-mk-locked-'.$status);
    $g['assessment']->update(['status' => $status]);
    $stale = markUpdate($g, ['score' => 9], base: 0);
    $create = markCreate($g, $g['student'], newCriterion($g));

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$stale, $create]));

    foreach ($results as $result) {
        expect($result['status'])->toBe('invalid');
        expect($result['reason'])->toBe('Marks cannot be changed while the assessment is finalized. Unlock it first.');
    }
    expect(DB::table('marks')->where('id', $g['mark']->id)->value('score'))->toBe(8);
})->with(['finalized', 'reports-generated']);

test('after an unlock the same edit is accepted', function () {
    $g = buildGraph('School A', '-mk-unlock');
    $g['teacher']->update(['is_admin' => true]);
    $g['assessment']->finalize($g['teacher']);
    $g['assessment']->unlock($g['teacher']);
    $entry = markUpdate($g, ['score' => 9]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
});

test('a recordId that is not the deterministic id of the triple is invalid, writes nothing, and is not a 500', function () {
    $g = buildGraph('School A', '-mk-wrong-id');
    $criterion = newCriterion($g);
    $entry = markCreate($g, $g['student'], $criterion);
    $entry['recordId'] = (string) Str::uuid7();

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'recordId is not the deterministic id of (assessmentId, studentId, criterionId)']);
    expect(DB::table('marks')->where('criterion_id', $criterion->id)->exists())->toBeFalse();
});

test('a create must carry the whole triple and a markKind', function (string $missing) {
    $g = buildGraph('School A', '-mk-missing');
    $entry = markCreate($g, $g['student'], newCriterion($g));
    unset($entry['fields'][$missing]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => "{$missing} is required"]);
})->with(['assessmentId', 'studentId', 'criterionId', 'markKind']);

test('a score over the criterion maximum is invalid, in a create and as a patch', function () {
    $g = buildGraph('School A', '-mk-over-max');
    $criterion = newCriterion($g, max: 10);
    $create = markCreate($g, $g['student'], $criterion, ['markKind' => 'score', 'score' => 11]);
    $patch = markUpdate($g, ['score' => 11]);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$create, $patch]));

    foreach ($results as $result) {
        expect($result['status'])->toBe('invalid');
        expect($result['reason'])->toBe('The score may not be greater than the criterion maximum.');
    }
    expect(DB::table('marks')->where('id', $g['mark']->id)->value('score'))->toBe(8);
});

test('the markKind and score pair is held together', function (array $cell, string $reason) {
    $g = buildGraph('School A', '-mk-pair');
    $entry = markCreate($g, $g['student'], newCriterion($g), $cell);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => $reason]);
})->with([
    'a score kind without a score' => [['markKind' => 'score'], 'score is required when markKind is score'],
    'a score on an absent mark' => [['markKind' => 'absent', 'score' => 5], 'score is only allowed when markKind is score'],
    'a score on an empty mark' => [['markKind' => 'empty', 'score' => 0], 'score is only allowed when markKind is score'],
    'an unknown markKind' => [['markKind' => 'present'], 'markKind must be one of empty, score, absent'],
    'a fractional score' => [['markKind' => 'score', 'score' => 7.5], 'score must be a whole number from 0 to the criterion maximum'],
    'a string score' => [['markKind' => 'score', 'score' => '7'], 'score must be a whole number from 0 to the criterion maximum'],
    'a negative score' => [['markKind' => 'score', 'score' => -1], 'score must be a whole number from 0 to the criterion maximum'],
    'a score beyond the integer range' => [['markKind' => 'score', 'score' => 99999999999], 'The score may not be greater than the criterion maximum.'],
]);

test('a malformed mark value is invalid, never a 500 that would stall the outbox', function (string $field, mixed $value) {
    $g = buildGraph('School A', '-mk-poison');
    $entry = markCreate($g, $g['student'], newCriterion($g));
    $entry['fields'][$field] = $value;
    $patch = markUpdate($g, [$field => $value]);

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry, $patch]));

    expect(array_column($results, 'status'))->toBe(['invalid', 'invalid']);
    expect(DB::table('marks')->where('id', $g['mark']->id)->value('version'))->toBe(1);
})->with([
    'an array markKind' => ['markKind', ['score']],
    'an array score' => ['score', [7]],
    'a boolean score' => ['score', true],
    'an array student id' => ['studentId', ['x']],
    'an integer assessment id' => ['assessmentId', 5],
    'a non-UUID criterion id' => ['criterionId', "x'; drop table marks; --"],
]);

test('a mark whose criterion cannot be resolved is invalid, not a 500', function () {
    $a = buildGraph('School A', '-mk-orphan-a');
    $b = buildGraph('School B', '-mk-orphan-b');
    $orphanId = (string) Str::uuid7();

    // A foreign key does not check the institution, so inconsistent data can exist; build it raw.
    DB::table('marks')->insert([
        'id' => $orphanId, 'institution_id' => $a['institution']->id, 'assessment_id' => $a['assessment']->id,
        'student_id' => $a['student']->id, 'criterion_id' => $b['criterion']->id,
        'mark_kind' => 'score', 'score' => 3, 'last_edited_by' => $a['teacher']->id, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $entry = pushEntry('marks', $orphanId, 1, ['score' => 5]);

    $result = postedResults(pushEntries($this, tokenFor($a['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'criterionId does not resolve']);
});

test('a patch is checked against the cell it patches', function () {
    $g = buildGraph('School A', '-mk-patch');
    $token = tokenFor($g['teacher']);

    $absent = markUpdate($g, ['markKind' => 'absent']);
    $cleared = postedResults(pushEntries($this, $token, [$absent]))[0];
    expect($cleared)->toBe(['id' => $absent['id'], 'status' => 'accepted', 'version' => 2]);
    expect(DB::table('marks')->where('id', $g['mark']->id)->first())->toMatchArray(['mark_kind' => 'absent', 'score' => null]);

    app('auth')->forgetGuards();
    $scoreOnAbsent = markUpdate($g, ['score' => 5], base: 2);
    $refused = postedResults(pushEntries($this, $token, [$scoreOnAbsent]))[0];
    expect($refused['status'])->toBe('invalid');
    expect($refused['reason'])->toBe('score is only allowed when markKind is score');

    app('auth')->forgetGuards();
    $back = markUpdate($g, ['markKind' => 'score', 'score' => 10], base: 2);
    $restored = postedResults(pushEntries($this, $token, [$back]))[0];
    expect($restored)->toBe(['id' => $back['id'], 'status' => 'accepted', 'version' => 3]);
});

test('an identity field in an update must match the cell, never move it', function () {
    $g = buildGraph('School A', '-mk-identity');
    $other = enrolledStudent($g, 'Someone else');

    $moves = markUpdate($g, ['studentId' => $other->id, 'score' => 9]);
    $same = markUpdate($g, ['studentId' => $g['student']->id, 'score' => 9]);

    $refused = postedResults(pushEntries($this, tokenFor($g['teacher']), [$moves]))[0];
    expect($refused)->toBe(['id' => $moves['id'], 'status' => 'invalid', 'reason' => 'studentId is immutable']);

    app('auth')->forgetGuards();
    $accepted = postedResults(pushEntries($this, tokenFor($g['teacher']), [$same]))[0];
    expect($accepted['status'])->toBe('accepted');
    expect(DB::table('marks')->where('id', $g['mark']->id)->value('student_id'))->toBe($g['student']->id);
});

test('deletedAt and lastEditedBy on a mark are invalid', function (string $field, string $reason) {
    $g = buildGraph('School A', '-mk-refused');
    $entry = markUpdate($g, [$field => 'x']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => $reason]);
})->with([
    'deletedAt' => ['deletedAt', 'a mark cannot be deleted; clear it with markKind empty'],
    'lastEditedBy' => ['lastEditedBy', 'server-owned field: lastEditedBy'],
]);

test('a stale base is a conflict carrying the current cell, and writes nothing', function () {
    $g = buildGraph('School A', '-mk-stale');
    $g['mark']->update(['score' => 6]);
    $entry = markUpdate($g, ['score' => 9], base: 1);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('conflict');
    expect($result['current']['version'])->toBe(2);
    expect($result['current']['fields']['score'])->toBe(6);
    expect($result['current']['fields']['markKind'])->toBe('score');
    expect(DB::table('marks')->where('id', $g['mark']->id)->value('score'))->toBe(6);
});

test('a base ahead of the server on a mark is invalid', function () {
    $g = buildGraph('School A', '-mk-ahead');
    $entry = markUpdate($g, ['score' => 9], base: 4);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'baseVersion is ahead of the server']);
});

test('a resent mark create is replayed, and nothing is written twice', function () {
    $g = buildGraph('School A', '-mk-replay');
    $token = tokenFor($g['teacher']);
    $entry = markCreate($g, $g['student'], newCriterion($g));

    postedResults(pushEntries($this, $token, [$entry]));
    app('auth')->forgetGuards();
    $result = postedResults(pushEntries($this, $token, [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 1, 'replayed' => true]);
    expect(DB::table('sync_changes')->where('record_id', $entry['recordId'])->count())->toBe(1);
});

test('a version that only moved who is credited does not block a stale mark edit', function () {
    $g = buildGraph('School A', '-mk-who-moved');
    $colleague = makeColleague($g, 'who-moved-mk@example.com');
    $resend = markUpdate($g, ['score' => 8]);
    $stale = markUpdate($g, ['score' => 9]);

    $first = postedResults(pushEntries($this, tokenFor($colleague), [$resend]))[0];
    expect($first)->toBe(['id' => $resend['id'], 'status' => 'accepted', 'version' => 2]);

    app('auth')->forgetGuards();
    $second = postedResults(pushEntries($this, tokenFor($g['teacher']), [$stale]))[0];

    expect($second)->toBe(['id' => $stale['id'], 'status' => 'merged', 'version' => 3]);
    expect(DB::table('marks')->where('id', $g['mark']->id)->first())->toMatchArray(['score' => 9, 'last_edited_by' => $g['teacher']->id, 'version' => 3]);
});

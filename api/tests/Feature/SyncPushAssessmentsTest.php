<?php

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Support\CurrentInstitution;
use Illuminate\Support\Facades\DB;

/* 3.2a, C4: updating an assessment over POST /sync. Who may edit is the policy's
   (AssessmentPolicyTest holds the whole matrix); here one refused role proves the
   endpoint asks. The rest is the version rules on a real record: a matching base
   applies, a stale base conflicts, a base ahead is invalid, and an unauthorized
   stale edit is forbidden, never a conflict. docs/spec/sync-protocol.md. */

function assessmentUpdate(array $graph, array $fields, int $base = 1, ?string $id = null): array
{
    return pushEntry('assessments', $graph['assessment']->id, $base, $fields, $id);
}

test('the creator updates the name and date at the current version, and only what changed is logged', function () {
    $g = buildGraph('School A', '-asm-update');
    $entry = assessmentUpdate($g, ['name' => 'CAT 1', 'date' => '2026-03-02']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 2]);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->first())->toMatchArray(['name' => 'CAT 1', 'date' => '2026-03-02', 'version' => 2]);

    $logged = DB::table('sync_changes')->where('record_id', $g['assessment']->id)->orderByDesc('seq')->first();
    expect($logged->version)->toBe(2);
    expect(json_decode($logged->fields, true))->toBe(['date' => '2026-03-02']);

    expect(DB::table('sync_mutations')->where('id', $entry['id'])->first())->toMatchArray(['status' => 'accepted', 'version' => 2]);
});

test('an update that changes nothing is accepted at the unchanged version and logs nothing', function () {
    $g = buildGraph('School A', '-asm-noop');
    $before = DB::table('sync_changes')->where('record_id', $g['assessment']->id)->count();
    $entry = assessmentUpdate($g, ['name' => 'CAT 1']);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'accepted', 'version' => 1]);
    expect(DB::table('sync_changes')->where('record_id', $g['assessment']->id)->count())->toBe($before);
});

test('a teacher assigned to the class and subject may update an assessment they did not create', function () {
    $g = buildGraph('School A', '-asm-assigned');
    $assigned = makeColleague($g, 'assigned-asm@example.com', assigned: true);
    $entry = assessmentUpdate($g, ['name' => 'CAT 1 (moderated)']);

    $result = postedResults(pushEntries($this, tokenFor($assigned), [$entry]))[0];

    expect($result['status'])->toBe('accepted');
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('created_by'))->toBe($g['teacher']->id);
});

test('a same-school teacher who neither created nor teaches it is forbidden and the assessment is unchanged', function () {
    $g = buildGraph('School A', '-asm-bystander');
    $bystander = makeColleague($g, 'bystander-asm@example.com');
    $entry = assessmentUpdate($g, ['name' => 'Hijacked']);

    $result = postedResults(pushEntries($this, tokenFor($bystander), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'forbidden']);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('name'))->toBe('CAT 1');
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('status'))->toBe('forbidden');
});

test('an unauthorized stale update is forbidden, never a conflict that would sit in the outbox forever', function () {
    $g = buildGraph('School A', '-asm-stale-forbidden');
    $bystander = makeColleague($g, 'stale-bystander-asm@example.com');
    $entry = assessmentUpdate($g, ['name' => 'Hijacked'], base: 0);

    $result = postedResults(pushEntries($this, tokenFor($bystander), [$entry]))[0];

    expect($result['status'])->toBe('forbidden');
});

test('a stale base is a conflict carrying the current row, writes nothing, and is recorded with the current version', function () {
    $g = buildGraph('School A', '-asm-stale');
    $g['assessment']->update(['name' => 'Renamed elsewhere']);
    $entry = assessmentUpdate($g, ['name' => 'My edit'], base: 1);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('conflict');
    expect($result['current']['version'])->toBe(2);
    expect($result['current']['fields']['name'])->toBe('Renamed elsewhere');
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('name'))->toBe('Renamed elsewhere');
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->first())->toMatchArray(['status' => 'conflict', 'version' => 2]);
});

test('a resent conflict is replayed with the current row read afresh after the record has moved on', function () {
    $g = buildGraph('School A', '-asm-replay-conflict');
    $token = tokenFor($g['teacher']);
    $g['assessment']->update(['name' => 'Second']);
    $entry = assessmentUpdate($g, ['name' => 'My edit'], base: 1);

    $first = postedResults(pushEntries($this, $token, [$entry]))[0];
    expect($first['current']['version'])->toBe(2);

    app(CurrentInstitution::class)->reset();
    $g['assessment']->refresh()->update(['name' => 'Third']);
    app('auth')->forgetGuards();

    $second = postedResults(pushEntries($this, $token, [$entry]))[0];

    expect($second['status'])->toBe('conflict');
    expect($second['replayed'])->toBeTrue();
    expect($second['current']['version'])->toBe(3);
    expect($second['current']['fields']['name'])->toBe('Third');
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->count())->toBe(1);
});

test('a base ahead of the server on an update is invalid', function () {
    $g = buildGraph('School A', '-asm-ahead');
    $entry = assessmentUpdate($g, ['name' => 'x'], base: 5);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'baseVersion is ahead of the server']);
});

test('class, subject, and year cannot change once marks exist', function (string $field) {
    $g = buildGraph('School A', '-asm-immutable-'.$field);
    $otherSubject = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Science']);
    ClassSubject::create(['institution_id' => $g['institution']->id, 'class_id' => $g['class']->id, 'subject_id' => $otherSubject->id]);
    $otherClass = SchoolClass::create(['institution_id' => $g['institution']->id, 'grade' => '5', 'stream' => 'East', 'class_teacher_id' => $g['teacher']->id]);
    ClassSubject::create(['institution_id' => $g['institution']->id, 'class_id' => $otherClass->id, 'subject_id' => $g['subject']->id]);
    $change = ['classId' => ['classId' => $otherClass->id], 'subjectId' => ['subjectId' => $otherSubject->id], 'year' => ['year' => 2027]][$field];
    $entry = assessmentUpdate($g, $change);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'The class, subject, and year cannot change after marks exist.']);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('version'))->toBe(1);
})->with(['classId', 'subjectId', 'year']);

test('before marks exist the class and subject may change, but only to a pair the class offers', function () {
    $g = buildGraph('School A', '-asm-before-marks');
    $unmarked = Assessment::create([
        'institution_id' => $g['institution']->id, 'class_id' => $g['class']->id, 'subject_id' => $g['subject']->id,
        'name' => 'Practical', 'term' => 1, 'year' => 2026, 'date' => '2026-02-10', 'status' => 'scheduled', 'created_by' => $g['teacher']->id,
    ]);
    $otherSubject = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Science']);
    $offered = pushEntry('assessments', $unmarked->id, 1, ['subjectId' => $otherSubject->id]);

    $notOffered = postedResults(pushEntries($this, tokenFor($g['teacher']), [$offered]))[0];

    expect($notOffered['status'])->toBe('invalid');
    expect($notOffered['reason'])->toBe('The selected class does not offer this subject.');

    ClassSubject::create(['institution_id' => $g['institution']->id, 'class_id' => $g['class']->id, 'subject_id' => $otherSubject->id]);
    app('auth')->forgetGuards();
    $moved = pushEntry('assessments', $unmarked->id, 1, ['subjectId' => $otherSubject->id]);

    $accepted = postedResults(pushEntries($this, tokenFor($g['teacher']), [$moved]))[0];

    expect($accepted['status'])->toBe('accepted');
    expect(DB::table('assessments')->where('id', $unmarked->id)->value('subject_id'))->toBe($otherSubject->id);
});

test('an assessment value Postgres would refuse is invalid on an update too, and the version does not move', function (string $field, mixed $value) {
    $g = buildGraph('School A', '-asm-update-poison');
    $entry = assessmentUpdate($g, [$field => $value]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('version'))->toBe(1);
})->with([
    'a NUL byte in the name' => ['name', "CAT\0 2"],
    'a blank name' => ['name', '   '],
    'a name over 255 characters' => ['name', str_repeat('a', 256)],
    'a non-UUID subject' => ['subjectId', '1 or 1=1'],
    'a subject that does not resolve' => ['subjectId', '0190b0d0-7c3a-7000-8000-000000000001'],
    'a term of 0' => ['term', 0],
    'a string year' => ['year', '2026'],
    'a date that is not real' => ['date', '2026-02-30'],
    'year zero in the date' => ['date', '0000-01-01'],
]);

test('an update to a soft-deleted assessment at the current version is invalid, not applied to the deleted row', function () {
    $g = buildGraph('School A', '-asm-deleted');
    $g['assessment']->delete();
    $entry = assessmentUpdate($g, ['name' => 'Edit of a deleted row'], base: 2);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result)->toBe(['id' => $entry['id'], 'status' => 'invalid', 'reason' => 'the assessment has been deleted']);
    expect(DB::table('assessments')->where('id', $g['assessment']->id)->value('name'))->toBe('CAT 1');
});

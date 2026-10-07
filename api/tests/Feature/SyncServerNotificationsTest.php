<?php

use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\CurrentInstitution;
use Illuminate\Support\Facades\DB;

/* 3.2c, D: notifications the server writes while handling a push. `edit-blocked`: a mark edit rejected
   because its assessment was finalized, which would otherwise make the teacher's work silently disappear
   (docs/spec/workflow.md); it is written in the fresh transaction that records the rejection, because the
   entry's own transaction rolled back. `sync-conflict`: a mark conflict raised, so both teachers see it.
   Both are Syncable rows, so they are logged and pulled like any other, and each recipient gets one unread
   notice per kind and assessment, not one per cell. */

/**
 * A finalized assessment with an assigned colleague, a colleague who will be rejected, and a bystander.
 *
 * @return array{0: array, 1: User, 2: User, 3: User}
 */
function blockedSetup(string $suffix): array
{
    $g = buildGraph('School A', $suffix);
    $assigned = makeColleague($g, "assigned{$suffix}@example.com", assigned: true);
    $rejected = makeColleague($g, "rejected{$suffix}@example.com");
    $bystander = makeColleague($g, "bystander{$suffix}@example.com");
    $g['assessment']->update(['status' => 'finalized']);

    return [$g, $assigned, $rejected, $bystander];
}

function noticesFor(User $user, string $kind): array
{
    return DB::table('notifications')->where('user_id', $user->id)->where('kind', $kind)->orderBy('id')->get()->all();
}

test('a mark edit rejected by a finalized assessment writes edit-blocked to the rejected teacher and each assigned teacher, and it is logged', function () {
    [$g, $assigned, $rejected, $bystander] = blockedSetup('-sn-block');
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);

    $result = postedResults(pushEntries($this, tokenFor($rejected), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect(DB::table('sync_mutations')->where('id', $entry['id'])->value('status'))->toBe('invalid');

    foreach ([$g['teacher'], $assigned, $rejected] as $recipient) {
        $notices = noticesFor($recipient, 'edit-blocked');
        expect($notices)->toHaveCount(1);
        expect($notices[0])->toMatchArray(['assessment_id' => $g['assessment']->id, 'tone' => 'danger', 'unread' => true, 'version' => 1]);
        expect(DB::table('sync_changes')->where('table', 'notifications')->where('record_id', $notices[0]->id)->count())->toBe(1);
    }
    expect(noticesFor($bystander, 'edit-blocked'))->toBe([]);
});

test('the notice names the assessment and says unlocking is the way forward', function () {
    [$g, , $rejected] = blockedSetup('-sn-copy');
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);

    postedResults(pushEntries($this, tokenFor($rejected), [$entry]));

    $notice = noticesFor($rejected, 'edit-blocked')[0];
    expect($notice->title)->toBe('Edit not applied: CAT 1');
    expect($notice->body)->toContain('CAT 1')->toContain('Grade 4 West')->toContain('Maths')->toContain('unlock');
});

test('an assessment name as long as its column does not break the notice: the title is cut, never a 500', function () {
    [$g, , $rejected] = blockedSetup('-sn-long');
    $g['assessment']->update(['name' => str_repeat('n', 255)]);
    $entry = pushEntry('marks', $g['mark']->id, 2, ['score' => 9]);

    $result = postedResults(pushEntries($this, tokenFor($rejected), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    $notice = noticesFor($rejected, 'edit-blocked')[0];
    expect(mb_strlen($notice->title))->toBeLessThanOrEqual(255);
    expect($notice->title)->toStartWith('Edit not applied: nnn');
});

test('a rejected teacher who is also assigned gets one notice, not two', function () {
    [$g] = blockedSetup('-sn-once');
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);

    postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]));

    expect(noticesFor($g['teacher'], 'edit-blocked'))->toHaveCount(1);
});

test('a deactivated assigned teacher, a deleted assignment, and an assignment to another subject get none', function () {
    [$g, , $rejected] = blockedSetup('-sn-filter');
    $deactivated = makeColleague($g, 'deactivated-sn@example.com', assigned: true);
    $deactivated->update(['deactivated_at' => now()]);
    $deletedAssignment = makeColleague($g, 'deleted-assign-sn@example.com', assigned: true);
    TeacherAssignment::query()->where('user_id', $deletedAssignment->id)->first()->delete();
    $otherSubject = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Science']);
    $elsewhere = makeColleague($g, 'elsewhere-sn@example.com');
    TeacherAssignment::create(['institution_id' => $g['institution']->id, 'user_id' => $elsewhere->id, 'class_id' => $g['class']->id, 'subject_id' => $otherSubject->id]);
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);

    postedResults(pushEntries($this, tokenFor($rejected), [$entry]));

    foreach ([$deactivated, $deletedAssignment, $elsewhere] as $user) {
        expect(noticesFor($user, 'edit-blocked'))->toBe([]);
    }
});

test('a resend of the rejected entry is replayed and writes no second notice', function () {
    [$g, , $rejected] = blockedSetup('-sn-replay');
    $token = tokenFor($rejected);
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 9]);

    postedResults(pushEntries($this, $token, [$entry]));
    $before = DB::table('notifications')->count();
    app('auth')->forgetGuards();
    $again = postedResults(pushEntries($this, $token, [$entry]))[0];

    expect($again['replayed'])->toBeTrue();
    expect(DB::table('notifications')->count())->toBe($before);
});

test('many rejected cells in one batch leave one unread notice per recipient, and after it is read a later rejection writes a new one', function () {
    [$g, $assigned, $rejected] = blockedSetup('-sn-flood');
    $criterion = newCriterion($g);
    $entries = [];

    foreach (range(1, 12) as $n) {
        $entries[] = markCreate($g, enrolledStudent($g, "Pupil {$n}"), $criterion);
    }

    $results = postedResults(pushEntries($this, tokenFor($rejected), $entries));

    expect(collect($results)->pluck('status')->unique()->all())->toBe(['invalid']);
    foreach ([$g['teacher'], $assigned, $rejected] as $recipient) {
        expect(noticesFor($recipient, 'edit-blocked'))->toHaveCount(1);
    }

    DB::table('notifications')->where('user_id', $rejected->id)->update(['unread' => false]);
    app('auth')->forgetGuards();
    postedResults(pushEntries($this, tokenFor($rejected), [pushEntry('marks', $g['mark']->id, 1, ['score' => 9])]));

    expect(noticesFor($rejected, 'edit-blocked'))->toHaveCount(2);
    expect(noticesFor($assigned, 'edit-blocked'))->toHaveCount(1);
});

test('an invalid mark for any other reason writes no notice', function () {
    $g = buildGraph('School A', '-sn-other-invalid');
    $entry = pushEntry('marks', $g['mark']->id, 1, ['score' => 999]);

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [$entry]))[0];

    expect($result['status'])->toBe('invalid');
    expect(DB::table('notifications')->count())->toBe(0);
});

test('only the recipient pulls the notice, with the assessment it concerns', function () {
    [$g, $assigned, $rejected, $bystander] = blockedSetup('-sn-pull');
    postedResults(pushEntries($this, tokenFor($rejected), [pushEntry('marks', $g['mark']->id, 1, ['score' => 9])]));

    app('auth')->forgetGuards();
    $asAssigned = $this->withToken(tokenFor($assigned))->getJson('/api/sync')->assertOk()->json('changes');
    app('auth')->forgetGuards();
    $asBystander = $this->withToken(tokenFor($bystander))->getJson('/api/sync')->assertOk()->json('changes');

    $notice = collect($asAssigned)->firstWhere('table', 'notifications');
    expect($notice['fields']['kind'])->toBe('edit-blocked');
    expect($notice['fields']['assessmentId'])->toBe($g['assessment']->id);
    expect(collect($asBystander)->where('table', 'notifications')->all())->toBe([]);
});

/* sync-conflict: a mark conflict raised. */

function raisedConflict(mixed $test, array $g, User $a, User $b, int $aScore = 6, int $bScore = 9): void
{
    postedResults(pushEntries($test, tokenFor($a), [pushEntry('marks', $g['mark']->id, 1, ['score' => $aScore])]));
    app('auth')->forgetGuards();
    postedResults(pushEntries($test, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => $bScore])]));
}

test('a cross-teacher conflict notifies both parties once', function () {
    $g = buildGraph('School A', '-sn-conflict');
    $b = makeColleague($g, 'b-sn-conflict@example.com');

    raisedConflict($this, $g, $g['teacher'], $b);

    foreach ([$g['teacher'], $b] as $party) {
        $notices = noticesFor($party, 'sync-conflict');
        expect($notices)->toHaveCount(1);
        expect($notices[0])->toMatchArray(['tone' => 'warning', 'title' => 'Mark conflict to settle', 'assessment_id' => $g['assessment']->id, 'unread' => true]);
        expect($notices[0]->body)->toContain('CAT 1')->toContain('Sync');
    }
});

test('a self-conflict notifies its one author once', function () {
    $g = buildGraph('School A', '-sn-self');

    raisedConflict($this, $g, $g['teacher'], $g['teacher']);

    expect(noticesFor($g['teacher'], 'sync-conflict'))->toHaveCount(1);
});

test('an auto conflict and an update against a deleted mark notify nobody', function () {
    $g = buildGraph('School A', '-sn-quiet');
    $b = makeColleague($g, 'b-sn-quiet@example.com');
    $other = buildGraph('School B', '-sn-quiet-b');
    raisedConflict($this, $g, $g['teacher'], $b, aScore: 6, bScore: 6);

    expect(DB::table('conflicts')->where('mark_id', $g['mark']->id)->count())->toBe(1);
    expect(DB::table('notifications')->count())->toBe(0);

    // The request left school A as the current institution; the delete for school B is an out-of-band write.
    app(CurrentInstitution::class)->reset();
    $other['mark']->delete();
    app('auth')->forgetGuards();
    postedResults(pushEntries($this, tokenFor($other['teacher']), [pushEntry('marks', $other['mark']->id, 1, ['score' => 9])]));

    expect(DB::table('notifications')->count())->toBe(0);
});

test('a second conflict on the assessment while the first notice is unread adds none', function () {
    $g = buildGraph('School A', '-sn-second');
    $b = makeColleague($g, 'b-sn-second@example.com');
    raisedConflict($this, $g, $g['teacher'], $b);
    app('auth')->forgetGuards();

    postedResults(pushEntries($this, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => 5])]));

    expect(DB::table('conflicts')->where('mark_id', $g['mark']->id)->count())->toBe(2);
    expect(noticesFor($g['teacher'], 'sync-conflict'))->toHaveCount(1);
    expect(noticesFor($b, 'sync-conflict'))->toHaveCount(1);
});

test('a party the institution scope hides is skipped, and nothing leaks to them', function () {
    $a = buildGraph('School A', '-sn-hidden-a');
    $other = buildGraph('School B', '-sn-hidden-b');
    $b = makeColleague($a, 'b-sn-hidden@example.com');
    DB::table('marks')->where('id', $a['mark']->id)->update(['last_edited_by' => $other['teacher']->id]);

    postedResults(pushEntries($this, tokenFor($b), [markCreate($a, $a['student'], $a['criterion'], ['markKind' => 'score', 'score' => 3])]));

    expect(noticesFor($b, 'sync-conflict'))->toHaveCount(1);
    expect(DB::table('notifications')->where('user_id', $other['teacher']->id)->count())->toBe(0);
});

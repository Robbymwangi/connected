<?php

use App\Jobs\SubmitCommentBatch;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\CommentGeneration;
use App\Models\Enrolment;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Models\TermClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/* 4.3, the term close (ADR 0012): POST /api/term-closures closes one stream's term once every
   assessment of it is finalized, writing the closure and one pending comment per enrolled student;
   while closed, no assessment enters or leaves the term; an unlock unwinds it.
   docs/spec/workflow.md (Term close, Unlock). */

// Generation is MR 3's concern and has its own tests; here a close is followed by a queued job, not a draft.
beforeEach(fn () => Bus::fake([SubmitCommentBatch::class]));

function closeTermRequest(mixed $test, array $graph, mixed $user, array $overrides = [])
{
    return $test->withToken(tokenFor($user))->postJson('/api/term-closures', array_merge([
        'class_id' => $graph['class']->id,
        'year' => 2026,
        'term' => 1,
    ], $overrides));
}

function finalizeGraph(array $graph): void
{
    $graph['assessment']->finalize($graph['teacher']);
}

test('a Director of Studies closes the term: a logged closure and one pending comment per live enrolment', function () {
    $g = buildGraph();
    $second = enrolledStudent($g, 'Second');
    $left = enrolledStudent($g, 'Left the stream');
    Enrolment::query()->where('student_id', $left->id)->sole()->delete();
    finalizeGraph($g);
    $this->travelTo('2026-04-01 09:00:00');

    $response = closeTermRequest($this, $g, asDirector($g['teacher']));

    $response->assertCreated()
        ->assertJsonPath('data.class_id', $g['class']->id)
        ->assertJsonPath('data.closed_by', $g['teacher']->id)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('meta', ['comments' => 2, 'skipped' => 0]);
    $closure = TermClosure::query()->sole();
    expect($closure->closed_at->toDateTimeString())->toBe('2026-04-01 09:00:00');
    expect(Comment::query()->orderBy('student_id')->get()->map->only(['student_id', 'year', 'term', 'term_closure_id', 'state', 'author_id', 'body'])->all())
        ->toEqualCanonicalizing(collect([$g['student'], $second])->map(fn ($student) => [
            'student_id' => $student->id, 'year' => 2026, 'term' => 1, 'term_closure_id' => $closure->id,
            'state' => 'pending', 'author_id' => null, 'body' => null,
        ])->all());
    expect(DB::table('sync_changes')->where('table', 'term_closures')->where('record_id', $closure->id)->exists())->toBeTrue();
    expect(DB::table('sync_changes')->where('table', 'comments')->count())->toBe(2);
});

test('the close queues generation for its closure once, and a no-op close queues nothing', function () {
    $g = buildGraph();
    finalizeGraph($g);

    $closureId = closeTermRequest($this, $g, asDirector($g['teacher']))->assertCreated()->json('data.id');
    closeTermRequest($this, $g, asDirector($g['teacher']))->assertOk();

    Bus::assertDispatchedTimes(SubmitCommentBatch::class, 1);
    Bus::assertDispatched(SubmitCommentBatch::class, fn (SubmitCommentBatch $job) => $job->termClosureId === $closureId
        && $job->institutionId === $g['institution']->id);
});

test('a Director of Studies closes a term without teaching the stream', function () {
    $g = buildGraph();
    $director = makeColleague($g, 'dos-close@example.com', directsStudies: true);
    finalizeGraph($g);

    closeTermRequest($this, $g, $director)->assertCreated()->assertJsonPath('data.closed_by', $director->id);
});

test('an admin account cannot close a term, even reaching the policy without the API door', function () {
    $g = buildGraph();
    $admin = makeColleague($g, 'admin-close@example.com', admin: true);

    expect(Gate::forUser($admin)->allows('create', [TermClosure::class, $g['class']]))->toBeFalse();
});

test('being the class teacher grants nothing: an unassigned class teacher is forbidden', function () {
    $g = buildGraph();
    $classTeacher = makeColleague($g, 'class-teacher@example.com');
    $g['class']->update(['class_teacher_id' => $classTeacher->id]);
    finalizeGraph($g);

    closeTermRequest($this, $g, $classTeacher)->assertForbidden();

    $this->assertDatabaseCount('term_closures', 0);
    $this->assertDatabaseCount('comments', 0);
});

test('a teacher assigned to the stream is forbidden without the grant: the close is the exams office\'s', function () {
    $g = buildGraph();
    finalizeGraph($g);

    closeTermRequest($this, $g, $g['teacher'])->assertForbidden();
    $this->assertDatabaseCount('term_closures', 0);
});

test('another school\'s stream does not resolve', function () {
    $g = buildGraph('School A', '-close-a');
    $b = buildGraph('School B', '-close-b');
    $b['teacher']->update(['directs_studies' => true]);
    finalizeGraph($g);

    closeTermRequest($this, $g, $b['teacher'])->assertUnprocessable()->assertInvalid(['class_id']);

    $this->assertDatabaseCount('term_closures', 0);
});

test('an assessment of the term still open makes the close invalid, naming it, and writes nothing', function () {
    $g = buildGraph();
    finalizeGraph($g);
    Assessment::create([
        'institution_id' => $g['institution']->id, 'class_id' => $g['class']->id, 'subject_id' => $g['subject']->id,
        'name' => 'End of Term', 'term' => 1, 'year' => 2026, 'date' => '2026-03-20', 'status' => 'scheduled', 'created_by' => $g['teacher']->id,
    ]);

    closeTermRequest($this, $g, asDirector($g['teacher']))
        ->assertUnprocessable()
        ->assertInvalid(['assessments' => 'Every assessment of the term must be finalized first. Still open: End of Term.']);

    $this->assertDatabaseCount('term_closures', 0);
    $this->assertDatabaseCount('comments', 0);
});

test('a deleted assessment is not open, and another term\'s or another stream\'s open assessment does not count', function () {
    $g = buildGraph();
    finalizeGraph($g);
    $base = ['institution_id' => $g['institution']->id, 'subject_id' => $g['subject']->id, 'year' => 2026, 'date' => '2026-03-20', 'status' => 'scheduled', 'created_by' => $g['teacher']->id];
    Assessment::create([...$base, 'class_id' => $g['class']->id, 'name' => 'Deleted', 'term' => 1])->delete();
    Assessment::create([...$base, 'class_id' => $g['class']->id, 'name' => 'Next term', 'term' => 2]);
    $otherClass = SchoolClass::create(['institution_id' => $g['institution']->id, 'grade' => '4', 'stream' => 'East']);
    Assessment::create([...$base, 'class_id' => $otherClass->id, 'name' => 'Other stream', 'term' => 1]);

    closeTermRequest($this, $g, asDirector($g['teacher']))->assertCreated();
});

test('a term with no assessments cannot be closed', function () {
    $g = buildGraph();
    finalizeGraph($g);

    closeTermRequest($this, $g, asDirector($g['teacher']), ['term' => 2])->assertUnprocessable()->assertInvalid(['term']);
});

test('closing a closed term is the no-op: 200 with the same closure and nothing new written', function () {
    $g = buildGraph();
    finalizeGraph($g);
    $first = closeTermRequest($this, $g, asDirector($g['teacher']))->assertCreated()->json('data.id');
    $logged = DB::table('sync_changes')->count();

    closeTermRequest($this, $g, asDirector($g['teacher']))
        ->assertOk()
        ->assertJsonPath('data.id', $first)
        ->assertJsonPath('meta', ['comments' => 0, 'skipped' => 0]);

    $this->assertDatabaseCount('term_closures', 1);
    $this->assertDatabaseCount('comments', 1);
    expect(DB::table('sync_changes')->count())->toBe($logged);
});

test('a student who already has a live comment for the term, from another stream\'s close, is skipped and counted', function () {
    $g = buildGraph();
    finalizeGraph($g);
    $earlier = SchoolClass::create(['institution_id' => $g['institution']->id, 'grade' => '4', 'stream' => 'East']);
    $earlierClosure = TermClosure::create(['institution_id' => $g['institution']->id, 'class_id' => $earlier->id, 'year' => 2026, 'term' => 1, 'closed_by' => $g['teacher']->id, 'closed_at' => now()]);
    $existing = $earlierClosure->comments()->create(['institution_id' => $g['institution']->id, 'student_id' => $g['student']->id, 'year' => 2026, 'term' => 1, 'state' => 'pending']);
    $second = enrolledStudent($g, 'Second');

    closeTermRequest($this, $g, asDirector($g['teacher']))->assertCreated()->assertJsonPath('meta', ['comments' => 1, 'skipped' => 1]);

    $closure = TermClosure::query()->where('class_id', $g['class']->id)->sole();
    expect($closure->comments()->pluck('student_id')->all())->toBe([$second->id]);
    expect($existing->fresh()->term_closure_id)->toBe($earlierClosure->id);
});

test('the term must be 1, 2, or 3 and the year an integer', function (array $overrides, string $field) {
    $g = buildGraph();

    closeTermRequest($this, $g, asDirector($g['teacher']), $overrides)->assertUnprocessable()->assertInvalid([$field]);
})->with([
    'term 4' => [['term' => 4], 'term'],
    'term 0' => [['term' => 0], 'term'],
    'year text' => [['year' => 'this year'], 'year'],
    'class not a uuid' => [['class_id' => 'class-4w'], 'class_id'],
]);

test('while the term is closed, an assessment cannot be created in it over sync', function () {
    // A close is allowed while its term is still open (ADR 0014, decision 5): report forms go home on closing day.
    $g = buildGraph('School A', '-closed-create');
    Term::query()->where('institution_id', $g['institution']->id)->update(['term' => 1]);
    closeGraphTerm($g);
    $id = Str::uuid7()->toString();

    $result = postedResults(pushEntries($this, tokenFor($g['teacher']), [
        pushEntry('assessments', $id, 0, assessmentFields($g, ['term' => 1])),
    ]))[0];

    expect($result['status'])->toBe('invalid');
    expect($result['reason'])->toContain('The term is closed for this stream');
    expect(Assessment::find($id))->toBeNull();
});

test('while the term is closed, an assessment cannot be moved into it or out of it, but can be renamed', function () {
    $g = buildGraph('School A', '-closed-move');
    $later = Assessment::create([
        'institution_id' => $g['institution']->id, 'class_id' => $g['class']->id, 'subject_id' => $g['subject']->id,
        'name' => 'Term 2 CAT', 'term' => 2, 'year' => 2026, 'date' => '2026-06-01', 'status' => 'scheduled', 'created_by' => $g['teacher']->id,
    ]);
    $g['mark']->delete();
    closeGraphTerm($g);
    $closedVersion = $g['assessment']->fresh()->version;

    $results = postedResults(pushEntries($this, tokenFor($g['teacher']), [
        pushEntry('assessments', $later->id, 1, ['term' => 1]),
        pushEntry('assessments', $g['assessment']->id, $closedVersion, ['term' => 2]),
        pushEntry('assessments', $g['assessment']->id, $closedVersion, ['name' => 'CAT 1 (renamed)']),
    ]));

    expect(array_column($results, 'status'))->toBe(['invalid', 'invalid', 'accepted']);
    expect($later->fresh()->term)->toBe(2);
    expect($g['assessment']->fresh()->only(['term', 'name']))->toBe(['term' => 1, 'name' => 'CAT 1 (renamed)']);
});

test('the unlock unwinds the close: closure, comments, and reports soft deleted, generations in flight superseded', function () {
    $g = buildGraph();
    $g['teacher']->update(['directs_studies' => true]);
    $closure = closeGraphTerm($g);
    $comment = $closure->comments()->sole();
    $inFlight = CommentGeneration::create(['institution_id' => $g['institution']->id, 'comment_id' => $comment->id, 'status' => 'submitted']);
    $settled = CommentGeneration::create(['institution_id' => $g['institution']->id, 'comment_id' => $comment->id, 'status' => 'errored']);

    $g['assessment']->unlock($g['teacher']);

    $this->assertSoftDeleted($closure);
    $this->assertSoftDeleted($comment);
    expect($inFlight->fresh()->status)->toBe('superseded');
    expect($settled->fresh()->status)->toBe('errored');
    expect(TermClosure::isClosed($g['class']->id, 2026, 1))->toBeFalse();
});

test('after an unlock, closing again writes a new closure and new comments beside the soft deleted history', function () {
    $g = buildGraph();
    $g['teacher']->update(['directs_studies' => true]);
    $first = closeGraphTerm($g);
    $firstComment = $first->comments()->sole();
    $g['assessment']->unlock($g['teacher']);
    finalizeGraph($g);

    closeTermRequest($this, $g, asDirector($g['teacher']))->assertCreated();

    $second = TermClosure::query()->sole();
    expect($second->id)->not->toBe($first->id);
    expect($second->comments()->sole()->id)->not->toBe($firstComment->id);
    expect(TermClosure::withTrashed()->count())->toBe(2);
    expect(Comment::withTrashed()->count())->toBe(2);
});

test('unlocking an assessment of another term leaves a closed term alone', function () {
    $g = buildGraph();
    $g['teacher']->update(['directs_studies' => true]);
    $closure = closeGraphTerm($g);
    $later = Assessment::create([
        'institution_id' => $g['institution']->id, 'class_id' => $g['class']->id, 'subject_id' => $g['subject']->id,
        'name' => 'Term 2 CAT', 'term' => 2, 'year' => 2026, 'date' => '2026-06-01', 'status' => 'scheduled', 'created_by' => $g['teacher']->id,
    ]);
    $later->finalize($g['teacher']);

    $later->unlock($g['teacher']);

    expect($closure->fresh()->trashed())->toBeFalse();
    expect($closure->comments()->count())->toBe(1);
});

test('a device pulls the closure and its comments', function () {
    $g = buildGraph();
    $closure = closeGraphTerm($g);

    $changes = collect($this->withToken(tokenFor($g['teacher']))->getJson('/api/sync?since=0')->assertOk()->json('changes'));

    $closureChange = $changes->firstWhere('recordId', $closure->id);
    expect($closureChange['table'])->toBe('term_closures');
    expect($closureChange['fields'])->toMatchArray(['classId' => $g['class']->id, 'year' => 2026, 'term' => 1, 'closedBy' => $g['teacher']->id]);
    expect($changes->firstWhere('table', 'comments')['fields'])->toMatchArray(['termClosureId' => $closure->id, 'state' => 'pending']);
});

<?php

use App\Jobs\GenerateReportCard;
use App\Jobs\SubmitCommentBatch;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\Notification;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\SyncChange;
use App\Models\TermClosure;
use App\Support\CurrentInstitution;
use App\Support\PerformanceLevel;
use App\Support\Reports\ReportCard;
use App\Support\SyncLog;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/* 4.4, report cards (ADR 0012; docs/spec/workflow.md, Report cards): accepting a comment queues the
   student's card; the job renders it from levels only, writes it to S3, and records it only if its
   comment and term closure are still live at the final write; the closer is told when the stream's
   last card is done; a card is downloaded through a presigned URL. */

beforeEach(function () {
    Storage::fake('s3');
    Bus::fake([SubmitCommentBatch::class, GenerateReportCard::class]);
});

/** The graph's Term 1 (Maths 8 of 10, EE) finalized with results and closed, every comment accepted. */
function acceptedTermOne(array $g): TermClosure
{
    $g['assessment']->finalize($g['teacher']);
    $closure = TermClosure::close(asDirector($g['teacher']), $g['class'], 2026, 1)['closure'];
    $closure->comments()->get()->each(fn (Comment $comment) => $comment->update([
        'state' => 'accepted', 'body' => 'A confident term in Maths.', 'author_id' => $g['teacher']->id,
    ]));

    return $closure;
}

/** A finalized assessment for the graph's subject, with each given student's score out of 10. */
function finalizedSitting(array $g, SchoolClass $class, int $year, int $term, array $scores): Assessment
{
    $assessment = Assessment::create([
        'institution_id' => $g['institution']->id, 'class_id' => $class->id, 'subject_id' => $g['subject']->id,
        'name' => "Sitting {$year}-{$term}", 'term' => $term, 'year' => $year, 'date' => "{$year}-0{$term}-15",
        'status' => 'scheduled', 'created_by' => $g['teacher']->id,
    ]);
    foreach ($scores as $studentId => $score) {
        Mark::create([
            'institution_id' => $g['institution']->id, 'assessment_id' => $assessment->id, 'student_id' => $studentId,
            'criterion_id' => $g['criterion']->id, 'mark_kind' => 'score', 'score' => $score, 'last_edited_by' => $g['teacher']->id,
        ]);
    }
    $assessment->finalize($g['teacher']);

    return $assessment;
}

function runReportJob(Comment $comment): void
{
    (new GenerateReportCard($comment->id, $comment->institution_id))->handle(app(CurrentInstitution::class));
}

test('accepting a comment queues its student\'s report card, after commit', function () {
    $g = buildGraph();
    $g['assessment']->finalize($g['teacher']);
    $comment = TermClosure::close(asDirector($g['teacher']), $g['class'], 2026, 1)['closure']->comments()->sole();
    $comment->update(['state' => 'draft', 'body' => 'Generated text.']);

    pushEntries($this, tokenFor($g['teacher']), [pushEntry('comments', $comment->id, $comment->fresh()->version, ['accept' => ['byId' => $g['teacher']->id]])]);

    Bus::assertDispatchedTimes(GenerateReportCard::class, 1);
    Bus::assertDispatched(GenerateReportCard::class, fn (GenerateReportCard $job) => $job->commentId === $comment->id && $job->institutionId === $g['institution']->id);
});

test('the job writes the PDF to S3 under the report\'s id and records the report, logged for pull', function () {
    $g = buildGraph();
    $comment = acceptedTermOne($g)->comments()->sole();

    runReportJob($comment);

    $report = Report::query()->sole();
    expect($report->only(['student_id', 'year', 'term']))->toBe(['student_id' => $g['student']->id, 'year' => 2026, 'term' => 1]);
    expect($report->s3_key)->toBe("reports/{$g['institution']->id}/{$report->id}.pdf");
    expect($report->generated_at)->not->toBeNull();
    expect(Storage::disk('s3')->get($report->s3_key))->toStartWith('%PDF-');
    expect(SyncChange::query()->where('table', 'reports')->where('record_id', $report->id)->exists())->toBeTrue();
});

test('the card carries levels for the closed terms of the year, earlier years, and the remark, and nothing else', function () {
    $g = buildGraph();
    $lastYear = SchoolClass::create(['institution_id' => $g['institution']->id, 'grade' => '3', 'stream' => 'East', 'class_teacher_id' => $g['teacher']->id]);
    Enrolment::create(['institution_id' => $g['institution']->id, 'student_id' => $g['student']->id, 'class_id' => $lastYear->id, 'year' => 2025]);
    finalizedSitting($g, $lastYear, 2025, 1, [$g['student']->id => 5]);
    finalizedSitting($g, $lastYear, 2025, 2, [$g['student']->id => 7]);
    $g['assessment']->finalize($g['teacher']);
    finalizedSitting($g, $g['class'], 2026, 2, [$g['student']->id => 5]);
    $closure = TermClosure::close(asDirector($g['teacher']), $g['class'], 2026, 2)['closure'];
    $comment = $closure->comments()->sole();
    $comment->update(['state' => 'accepted', 'body' => 'Steady progress.']);

    $card = ReportCard::for($comment->fresh(), $closure);

    expect(array_keys($card))->toBe(['school', 'student', 'stream', 'year', 'term', 'subjects', 'history', 'remark', 'levels']);
    // Term 1 has results but was never closed, so it is blank; Term 3 is still to come.
    expect($card['subjects'])->toBe([['subject' => 'Maths', 'terms' => [1 => null, 2 => PerformanceLevel::AE, 3 => null]]]);
    // 2025: the mean of a 50 percent term and a 70 percent term is 60 percent, ME.
    expect($card['history'])->toBe([['year' => 2025, 'stream' => 'Grade 3 East', 'subjects' => [['subject' => 'Maths', 'level' => PerformanceLevel::ME]]]]);
    expect($card)->toMatchArray(['school' => 'Test School', 'student' => 'S. Student', 'stream' => 'Grade 4 West', 'year' => 2026, 'term' => 2, 'remark' => 'Steady progress.']);

    $text = strip_tags(substr(view('reports.card', $card)->render(), strpos(view('reports.card', $card)->render(), '<body>')));
    expect($text)->toContain('Steady progress.')->toContain('AE')->toContain('Grade 3 East')->not->toContain('%')->not->toContain('50')->not->toContain('60');
});

test('a job whose cycle was unwound, or whose comment is not accepted, writes nothing', function (string $case) {
    $g = buildGraph();
    $closure = acceptedTermOne($g);
    $comment = $closure->comments()->sole();
    match ($case) {
        'unwound' => inSyncLog($g, fn () => TermClosure::query()->find($closure->id)->unwind()),
        'not accepted' => $comment->update(['state' => 'draft']),
    };

    runReportJob($comment);

    expect(Report::withTrashed()->count())->toBe(0);
    expect(Storage::disk('s3')->allFiles())->toBe([]);
})->with(['unwound', 'not accepted']);

test('an unwind that lands while the PDF is being written leaves no report and no object', function () {
    $g = buildGraph();
    $closure = acceptedTermOne($g);
    $fake = Storage::disk('s3');
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnUsing(function (string $key, string $pdf) use ($fake, $closure, $g) {
        $fake->put($key, $pdf);
        inSyncLog($g, fn () => TermClosure::query()->find($closure->id)->unwind());

        return true;
    });
    $disk->shouldReceive('delete')->once()->andReturnUsing(fn (string $key) => $fake->delete($key));
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

    runReportJob($closure->comments()->withTrashed()->sole());

    expect(Report::withTrashed()->count())->toBe(0);
    expect($fake->allFiles())->toBe([]);
});

test('a retry after the card was recorded writes nothing more', function () {
    $g = buildGraph();
    $comment = acceptedTermOne($g)->comments()->sole();

    runReportJob($comment);
    runReportJob($comment);

    expect(Report::query()->count())->toBe(1);
    expect(Storage::disk('s3')->allFiles())->toHaveCount(1);
});

test('the closer is told once the stream\'s last card is done, not before', function () {
    $g = buildGraph();
    $second = enrolledStudent($g, 'Pupil Two');
    Mark::create([
        'institution_id' => $g['institution']->id, 'assessment_id' => $g['assessment']->id, 'student_id' => $second->id,
        'criterion_id' => $g['criterion']->id, 'mark_kind' => 'score', 'score' => 6, 'last_edited_by' => $g['teacher']->id,
    ]);
    [$first, $last] = acceptedTermOne($g)->comments()->orderBy('id')->get()->all();

    runReportJob($first);
    expect(Notification::query()->where('kind', 'report-ready')->exists())->toBeFalse();

    runReportJob($last);
    $notice = Notification::query()->where('kind', 'report-ready')->sole();
    expect($notice->only(['user_id', 'term_closure_id', 'tone', 'unread']))->toBe([
        'user_id' => $g['teacher']->id, 'term_closure_id' => $last->term_closure_id, 'tone' => 'success', 'unread' => true,
    ]);
    expect($notice->title.$notice->body)->not->toContain('Pupil Two')->not->toContain('S. Student');
});

test('a card is downloaded through a short-lived presigned URL, within the institution only', function () {
    $g = buildGraph();
    $report = issuedReport($g);
    Storage::fake('s3-public');
    Storage::disk('s3-public')->buildTemporaryUrlsUsing(fn (string $path, DateTimeInterface $expiresAt) => "https://storage.test/{$path}?expires={$expiresAt->getTimestamp()}");
    $other = buildGraph('Other School', '-other');

    $this->withToken(tokenFor($g['teacher']))->getJson("/api/reports/{$report->id}/download")
        ->assertOk()
        ->assertJsonPath('data.url', 'https://storage.test/reports/student.pdf?expires='.now()->addMinutes(10)->getTimestamp());
    $this->app['auth']->forgetGuards();
    $this->withToken(tokenFor($other['teacher']))->getJson("/api/reports/{$report->id}/download")->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->withoutToken()->getJson("/api/reports/{$report->id}/download")->assertUnauthorized();
});

function inSyncLog(array $g, Closure $callback): mixed
{
    return SyncLog::transaction($g['institution']->id, $callback);
}

<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Models\Report;
use App\Models\TermClosure;
use App\Support\CurrentInstitution;
use App\Support\Reports\ReportCard;
use App\Support\SyncLog;
use App\Sync\ServerNotifications;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/* One student's report card for one term (build plan 4.4, ADR 0012, docs/spec/workflow.md, Report
   cards), queued after commit when that student's comment is accepted. Three steps, with no
   transaction held across rendering or the S3 write, as for the comment batch (ADR 0007):

   1. Read what the card says (ReportCard) and render it with Dompdf.
   2. Write the PDF to S3 under a key named by the new report's id, never by the student.
   3. In one short transaction, behind the institution's advisory lock, check what the job was queued
      for: the comment still live and accepted, its term closure still live, and no live card yet for
      the student's term. Only then is the reports row written, with generated_at and the key. If that
      was the stream's last card, the user who closed the term is told. If the check fails (an unlock
      unwound the cycle meanwhile, or a retry already wrote the card), nothing is recorded and the
      object written in step 2 is deleted.

   A crash between steps 2 and 3 leaves an object no row names; a retry writes a new one under a new
   key. That orphan is harmless, holds no name in its key, and is the price of not holding a lock
   across S3. */
class GenerateReportCard implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $commentId,
        public readonly string $institutionId,
    ) {}

    public function handle(CurrentInstitution $current): void
    {
        $current->actingFor($this->institutionId, fn () => $this->run());
    }

    private function run(): void
    {
        $comment = Comment::query()->find($this->commentId);
        $closure = $comment === null ? null : TermClosure::query()->find($comment->term_closure_id);

        if ($comment === null || $closure === null || $comment->state !== 'accepted' || $this->alreadyIssued($comment)) {
            return;
        }

        $pdf = self::render(ReportCard::for($comment, $closure));
        $reportId = (string) Str::uuid7();
        $key = "reports/{$this->institutionId}/{$reportId}.pdf";

        if (! Storage::disk('s3')->put($key, $pdf, ['ContentType' => 'application/pdf'])) {
            throw new RuntimeException('the report card could not be written to storage');
        }

        $recorded = SyncLog::transaction($this->institutionId, fn () => $this->record($reportId, $key));

        if (! $recorded) {
            Storage::disk('s3')->delete($key);
        }
    }

    /** Step 3: the check at the final write, and the write. */
    private function record(string $reportId, string $key): bool
    {
        $comment = Comment::query()->lockForUpdate()->find($this->commentId);
        $closure = $comment === null ? null : TermClosure::query()->find($comment->term_closure_id);

        if ($comment === null || $closure === null || $comment->state !== 'accepted' || $this->alreadyIssued($comment)) {
            return false;
        }

        Report::create([
            'id' => $reportId,
            'institution_id' => $this->institutionId,
            'student_id' => $comment->student_id,
            'year' => $comment->year,
            'term' => $comment->term,
            'generated_at' => now(),
            's3_key' => $key,
        ]);

        if (! $closure->comments()->whereNotIn('student_id', $closure->reports()->select('student_id'))->exists()) {
            app(ServerNotifications::class)->reportsReady($closure);
        }

        return true;
    }

    private function alreadyIssued(Comment $comment): bool
    {
        return Report::query()
            ->where('student_id', $comment->student_id)
            ->where('year', $comment->year)
            ->where('term', $comment->term)
            ->exists();
    }

    /**
     * The card as PDF bytes. Remote fetching stays off (Dompdf's default): the template is
     * self-contained, and a remark is the one piece of text not written by us.
     *
     * @param  array<string, mixed>  $card
     */
    public static function render(array $card): string
    {
        $dompdf = new Dompdf(new Options([
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'tempDir' => sys_get_temp_dir(),
            'fontCache' => storage_path('framework/cache'),
        ]));
        $dompdf->loadHtml(view('reports.card', $card)->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}

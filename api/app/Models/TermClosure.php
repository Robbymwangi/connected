<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use App\Support\SyncLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/* One stream's term, closed: written by the term close once every assessment of the term is
   finalized, soft deleted by an unlock that unwinds it. One live row per (class_id, year, term).
   Synchronisable and pull-only, so a device knows the term is closed and can show the review
   screen. docs/spec/workflow.md (Term close), docs/spec/data-model.md. */
#[Fillable(['id', 'institution_id', 'class_id', 'year', 'term', 'closed_by', 'closed_at'])]
class TermClosure extends Model
{
    use BelongsToInstitution, Syncable;

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Whether the stream's term is closed now; the one question every guard on a closed term asks. */
    public static function isClosed(string $classId, int $year, int $term): bool
    {
        return self::liveFor($classId, $year, $term)->exists();
    }

    public static function liveFor(string $classId, int $year, int $term): Builder
    {
        return self::query()->where('class_id', $classId)->where('year', $year)->where('term', $term);
    }

    /* The term close (docs/spec/workflow.md, Term close). Inside SyncLog::transaction, so behind the
       institution's advisory lock like every sync write, the stream's assessments for the year and
       term are locked in id order and rechecked under the lock: the same lock order as finalize and
       unlock (advisory lock, then assessments), so neither can commit an open assessment into a
       closed term. An already closed term is the no-op. Otherwise it writes the closure and one
       pending comment, with no author and no body, per student with a live enrolment in the stream
       that year; a student who already has a live comment for the term (closed from another stream
       they were in earlier) is skipped and counted, since a comment is one per student per term.

       @return array{closure: self, created: bool, comments: int, skipped: int} */
    public static function close(User $user, SchoolClass $schoolClass, int $year, int $term): array
    {
        return SyncLog::transaction($schoolClass->institution_id, function () use ($user, $schoolClass, $year, $term): array {
            Gate::forUser($user)->authorize('create', [self::class, $schoolClass]);

            $assessments = Assessment::query()
                ->where('class_id', $schoolClass->id)
                ->where('year', $year)
                ->where('term', $term)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $existing = self::liveFor($schoolClass->id, $year, $term)->first();

            if ($existing !== null) {
                return ['closure' => $existing, 'created' => false, 'comments' => 0, 'skipped' => 0];
            }

            if ($assessments->isEmpty()) {
                throw ValidationException::withMessages([
                    'term' => 'The stream has no assessments in this term to close.',
                ]);
            }

            $open = $assessments->reject(fn (Assessment $assessment) => in_array($assessment->status, Assessment::FINALIZED, true));

            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'assessments' => 'Every assessment of the term must be finalized first. Still open: '.$open->pluck('name')->sort()->implode(', ').'.',
                ]);
            }

            $closure = self::create([
                'institution_id' => $schoolClass->institution_id,
                'class_id' => $schoolClass->id,
                'year' => $year,
                'term' => $term,
                'closed_by' => $user->id,
                'closed_at' => now(),
            ]);

            $studentIds = Enrolment::query()
                ->where('class_id', $schoolClass->id)
                ->where('year', $year)
                ->whereHas('student')
                ->orderBy('student_id')
                ->pluck('student_id');

            $taken = Comment::query()
                ->whereIn('student_id', $studentIds)
                ->where('year', $year)
                ->where('term', $term)
                ->pluck('student_id');

            $pending = $studentIds->diff($taken);

            foreach ($pending as $studentId) {
                $closure->comments()->create([
                    'institution_id' => $schoolClass->institution_id,
                    'student_id' => $studentId,
                    'year' => $year,
                    'term' => $term,
                    'state' => 'pending',
                ]);
            }

            return ['closure' => $closure, 'created' => true, 'comments' => $pending->count(), 'skipped' => $taken->count()];
        });
    }

    /** The live report cards issued under this close: its comments' students, for its year and term. */
    public function reports(): Builder
    {
        return Report::query()
            ->whereIn('student_id', $this->comments()->select('student_id'))
            ->where('year', $this->year)
            ->where('term', $this->term);
    }

    /* Unwinds the close, from inside an unlock's transaction (docs/spec/workflow.md, Unlock): its
       comments and their report cards are soft deleted, any generation still in flight for them is
       superseded so a late result cannot apply, and the closure itself is soft deleted. Nothing is
       overwritten; the next close inserts new rows beside this history. Rows are locked in id order. */
    public function unwind(): void
    {
        $comments = $this->comments()->orderBy('id')->lockForUpdate()->get();
        $reports = $this->reports()->orderBy('id')->lockForUpdate()->get();

        CommentGeneration::query()
            ->whereIn('comment_id', $comments->modelKeys())
            ->where('status', 'submitted')
            ->update(['status' => 'superseded']);

        foreach ($reports as $report) {
            $report->delete();
        }

        foreach ($comments as $comment) {
            $comment->delete();
        }

        $this->delete();
    }
}

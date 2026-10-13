<?php

namespace App\Sync;

use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Conflict;
use App\Models\Notification;
use App\Models\SubjectModeration;
use App\Models\TeacherAssignment;
use App\Models\TermClosure;
use App\Models\User;
use Illuminate\Support\Str;

/* The notifications the server writes while handling a push (docs/spec/workflow.md, edit-blocked;
   docs/spec/data-model.md, notifications). They are ordinary Notification rows, so they are Syncable:
   logged, versioned, and pulled by the recipient like any other, with no push infrastructure.

   Recipients are looked up through the institution scope and exclude soft-deleted and deactivated
   users, and an assignment that has been deleted no longer counts. A recipient who already has an
   unread notice of the same kind and title for the same assessment gets no second one: without that, editing a
   finalized 45-student by 5-criterion grid would write 225 notices to every recipient. The advisory
   lock every push already holds makes that check race-free. Once the notice is read, a later event
   writes a new one.

   The title is cut to the column's length: an assessment name can be as long as the column itself,
   and a value the database refuses would be a 5xx that stalls the device's outbox on every resend. */
final class ServerNotifications
{
    private const TITLE_LIMIT = 250;

    /* A mark edit was rejected because the assessment is finalized. Written in the fresh transaction that
       records the rejection (SyncPush::reject), never in the entry's own, which has rolled back. The
       rejected teacher is always a recipient, plus whoever is assigned to the assessment's class and subject. */
    public function editBlocked(User $actor, string $assessmentId): void
    {
        $assessment = Assessment::withTrashed()->with(['schoolClass', 'subject'])->find($assessmentId);

        if ($assessment === null) {
            return;
        }

        $assigned = TeacherAssignment::query()
            ->where('class_id', $assessment->class_id)
            ->where('subject_id', $assessment->subject_id)
            ->pluck('user_id')
            ->all();

        $this->notify(
            [...$assigned, $actor->id],
            $assessment,
            'edit-blocked',
            'danger',
            "Edit not applied: {$assessment->name}",
            "A mark change by {$actor->name} reached the server after {$this->describe($assessment)} was finalized, so it was not applied. The Director of Studies or the subject's head of department can unlock the assessment; the mark can then be entered again.",
        );
    }

    /* A mark conflict was raised: both parties, or the one author of a self-conflict, are told. Not called
       for an auto conflict (nothing to settle) or for the delete case (no record). */
    public function conflictRaised(Conflict $conflict, string $assessmentId): void
    {
        $assessment = Assessment::withTrashed()->with(['schoolClass', 'subject'])->find($assessmentId);

        if ($assessment === null) {
            return;
        }

        $this->notify(
            array_filter([$conflict->side_a['userId'] ?? null, $conflict->side_b['userId'] ?? null]),
            $assessment,
            'sync-conflict',
            'warning',
            'Mark conflict to settle',
            "{$this->describe($assessment)}: two edits to the same mark disagree. Open Sync to settle it.",
        );
    }

    /* A conflict was referred to the subject's moderators (a party chose to, or the rounds bound was reached). Only
       moderators who are not parties are told: a party who moderates acts as a party, and already knows. The referral
       is what obliges a moderator to act (ADR 0002 rule 5), so it has its own title and is not hidden by an unread
       notice that a conflict was raised. */
    public function referred(Conflict $conflict, Assessment $assessment): void
    {
        $assessment->loadMissing(['schoolClass', 'subject']);

        $parties = array_filter([$conflict->side_a['userId'] ?? null, $conflict->side_b['userId'] ?? null]);
        $moderators = SubjectModeration::query()->where('subject_id', $assessment->subject_id)->pluck('user_id')->all();

        $this->notify(
            array_diff($moderators, $parties),
            $assessment,
            'sync-conflict',
            'warning',
            'Conflict referred to you',
            "{$this->describe($assessment)}: a conflict between two teachers was referred to you. Open Sync to settle it.",
        );
    }

    /* A comment of a term close failed to generate (docs/spec/workflow.md, Comments): the user who closed
       the term is told, once per term close while the notice is unread, not once per student. Called by
       CommentGeneration::settle, the one place a comment becomes failed, inside its transaction. The
       notice names the stream and term, never a student: it points to the review screen, where the
       failed comments are. */
    public function commentsFailed(Comment $comment): void
    {
        $closure = TermClosure::query()->with(['schoolClass', 'closedBy'])->find($comment->term_closure_id);

        if ($closure === null) {
            return;
        }

        $stream = $this->stream($closure);

        $this->notifyCloser(
            $closure,
            'comments-failed',
            'warning',
            "Comments not drafted: {$stream}, Term {$closure->term}",
            "Some comments for {$stream}, Term {$closure->term} {$closure->year} could not be drafted. Open the term's comments to write them or regenerate them.",
        );
    }

    /* Every student of a term close has a report card (docs/spec/workflow.md, Report cards): the user
       who closed the term is told, by the report job that wrote the last card, inside its transaction. */
    public function reportsReady(TermClosure $closure): void
    {
        $closure->loadMissing(['schoolClass', 'closedBy']);
        $stream = $this->stream($closure);

        $this->notifyCloser(
            $closure,
            'report-ready',
            'success',
            "Report cards ready: {$stream}, Term {$closure->term}",
            "Every report card for {$stream}, Term {$closure->term} {$closure->year} has been generated.",
        );
    }

    /* One unread notice per kind and term close, to the closer, unless they have been deactivated. */
    private function notifyCloser(TermClosure $closure, string $kind, string $tone, string $title, string $body): void
    {
        $user = $closure->closedBy;

        if ($user === null || $user->deactivated_at !== null) {
            return;
        }

        $alreadyUnread = Notification::query()
            ->where('user_id', $user->id)
            ->where('kind', $kind)
            ->where('term_closure_id', $closure->id)
            ->where('unread', true)
            ->exists();

        if ($alreadyUnread) {
            return;
        }

        Notification::create([
            'institution_id' => $closure->institution_id,
            'user_id' => $user->id,
            'term_closure_id' => $closure->id,
            'kind' => $kind,
            'tone' => $tone,
            'title' => Str::limit($title, self::TITLE_LIMIT),
            'body' => $body,
            'unread' => true,
        ]);
    }

    private function stream(TermClosure $closure): string
    {
        return "Grade {$closure->schoolClass?->grade} {$closure->schoolClass?->stream}";
    }

    /**
     * @param  array<int, string>  $userIds
     */
    private function notify(array $userIds, Assessment $assessment, string $kind, string $tone, string $title, string $body): void
    {
        $title = Str::limit($title, self::TITLE_LIMIT);
        $recipients = User::query()->whereIn('id', array_values(array_unique($userIds)))->whereNull('deactivated_at')->get();

        foreach ($recipients as $user) {
            $alreadyUnread = Notification::query()
                ->where('user_id', $user->id)
                ->where('kind', $kind)
                ->where('title', $title)
                ->where('assessment_id', $assessment->id)
                ->where('unread', true)
                ->exists();

            if ($alreadyUnread) {
                continue;
            }

            Notification::create([
                'institution_id' => $assessment->institution_id,
                'user_id' => $user->id,
                'assessment_id' => $assessment->id,
                'kind' => $kind,
                'tone' => $tone,
                'title' => $title,
                'body' => $body,
                'unread' => true,
            ]);
        }
    }

    private function describe(Assessment $assessment): string
    {
        return "{$assessment->name}, Grade {$assessment->schoolClass?->grade} {$assessment->schoolClass?->stream}, {$assessment->subject?->name}";
    }
}

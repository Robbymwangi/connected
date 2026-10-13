<?php

namespace App\Sync\Push;

use App\Jobs\GenerateReportCard;
use App\Jobs\SubmitCommentBatch;
use App\Models\Comment;
use App\Models\CommentGeneration;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/* Comments over POST /sync, as three commands, never field patches (docs/spec/sync-protocol.md, "Comment
   commands"; docs/spec/workflow.md, Comments). Each names what it acts on, is checked against the comment's
   stored state, and is answered only at the comment's own version: a stale base gets the comment as it stands,
   so an acceptance can never go through over a body the acceptor did not see.

   - edit: the teacher's text replaces the body; the comment becomes a draft with them as author. Allowed while
     pending, draft, or failed.
   - accept: a draft becomes accepted with the acceptor as author, and the student's report card is queued. Final until an unlock.
   - regenerate: the body and author are cleared and the comment is pending again; generation is queued after
     commit. Allowed while pending, draft, or failed.

   Edit and regenerate supersede any generation still in flight, after the comment is saved, so the lock order is
   the comment, then its generations, as unwind() has it. Any authenticated user of the institution may act,
   consistent with grading being unrestricted; the global scope means another school's comment is never found. */
final class CommentPushHandler extends PushHandler
{
    /** A remark that fits the report card. */
    public const BODY_LIMIT = 600;

    /** Which states each command may act on. */
    private const FROM = [
        'edit' => ['pending', 'draft', 'failed'],
        'accept' => ['draft'],
        'regenerate' => ['pending', 'draft', 'failed'],
    ];

    private string $command = '';

    private string $body = '';

    public function columns(): array
    {
        return ['edit' => 'body', 'accept' => 'state', 'regenerate' => 'state'];
    }

    public function refused(): array
    {
        return [
            'body' => 'a comment is changed by command (edit, accept, regenerate), never by field',
            'state' => 'a comment is changed by command (edit, accept, regenerate), never by field',
            'authorId' => 'the author is whoever acts, set by the server',
        ];
    }

    public function isCommand(): bool
    {
        return true;
    }

    public function incoming(PushEntry $entry, Model $record): array
    {
        throw new LogicException('A command is never decided by rule 4.');
    }

    public function find(string $recordId, bool $lock = false): ?Model
    {
        $query = Comment::withTrashed();

        return ($lock ? $query->lockForUpdate() : $query)->find($recordId);
    }

    public function authorize(?Model $record): bool
    {
        return true;
    }

    /* The shape, before any version rule: one command, byId the signed-in user, and for an edit a body that fits. */
    public function check(PushEntry $entry, ?Model $record): void
    {
        if ($record === null) {
            throw SyncRejection::invalid('comments are created by the term close');
        }

        if (count($entry->fields) !== 1) {
            throw SyncRejection::invalid('a comment command is sent on its own: one of edit, accept, or regenerate');
        }

        $this->command = (string) array_key_first($entry->fields);
        $value = $entry->fields[$this->command];
        $keys = $this->command === 'edit' ? ['byId', 'body'] : ['byId'];

        if (! is_array($value) || array_is_list($value) || array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []) {
            throw SyncRejection::invalid("{$this->command} carries exactly: ".implode(', ', $keys));
        }

        if (! is_string($value['byId']) || $value['byId'] !== (string) $this->user->id) {
            throw SyncRejection::invalid('byId must be the signed-in user');
        }

        if ($this->command === 'edit') {
            $body = is_string($value['body']) ? trim($value['body']) : '';

            if ($body === '' || mb_strlen($body) > self::BODY_LIMIT || str_contains($body, "\0")) {
                throw SyncRejection::invalid('body must be non-blank text of at most '.self::BODY_LIMIT.' characters');
            }

            $this->body = $body;
        }
    }

    /* Only at an equal version, so the state checked is the one the device saw. Returned unsaved. */
    public function resulting(PushEntry $entry, ?Model $record): Model
    {
        if ($record->trashed()) {
            throw SyncRejection::invalid('the comment has been withdrawn by an unlock');
        }

        if (! in_array($record->state, self::FROM[$this->command], true)) {
            throw SyncRejection::invalid($record->state === 'accepted'
                ? 'an accepted comment is final until an unlock'
                : "a {$record->state} comment has no text to accept");
        }

        return $record->fill(match ($this->command) {
            'edit' => ['body' => $this->body, 'state' => 'draft', 'author_id' => $this->user->id],
            'accept' => ['state' => 'accepted', 'author_id' => $this->user->id],
            'regenerate' => ['body' => null, 'state' => 'pending', 'author_id' => null],
        });
    }

    /* Inside the entry's transaction: an edit or a regenerate supersedes what is in flight, and a regenerate
       queues generation for its term close once this commits. The submit job sends only pending comments with
       nothing in flight, so it sends exactly this one. Accepting will queue the report card (4.4). */
    public function written(PushEntry $entry, Model $saved): void
    {
        if ($this->command === 'accept') {
            GenerateReportCard::dispatch($saved->id, $saved->institution_id)->afterCommit();

            return;
        }

        CommentGeneration::query()
            ->where('comment_id', $saved->id)
            ->where('status', 'submitted')
            ->update(['status' => 'superseded']);

        if ($this->command === 'regenerate') {
            SubmitCommentBatch::dispatch($saved->term_closure_id, $saved->institution_id)->afterCommit();
        }
    }
}

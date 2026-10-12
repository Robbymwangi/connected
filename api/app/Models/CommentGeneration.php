<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Server bookkeeping for one request to the batch API; its id is the request's custom_id. Not
   synchronisable, like UnlockNote: no version and no soft delete, and nothing here is ever sent to a
   device. A comment's latest generation is the one with the latest created_at; a result applies only
   if it is that one, the comment is live, and the comment is still pending (docs/spec/workflow.md,
   Comments). docs/spec/data-model.md. */
#[Fillable(['id', 'institution_id', 'comment_id', 'batch_id', 'status', 'error'])]
class CommentGeneration extends Model
{
    use BelongsToInstitution, HasUuidv7;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class)->withTrashed();
    }

    /* The one rule for a generation's outcome (docs/spec/workflow.md, Comments): it applies only if
       this is the comment's latest generation and still submitted, and the comment is live and
       still pending. That covers a regenerate while a batch is in flight, an edit that overtook
       generation, an unlock mid-batch, and a duplicate submit after a crash. Applying text makes
       the comment a draft with no author; applying a failure makes it failed. Otherwise the
       generation is superseded and the comment is untouched. The comment is written through
       Syncable, so its version moves and a device sees the change by pull.

       Call inside SyncLog::transaction, and never across an HTTP call (ADR 0007): the comment row
       is locked first, then this one, the order unwind() uses. Returns whether it applied. */
    public function settle(string $status, ?string $text = null, ?string $error = null): bool
    {
        $comment = Comment::withTrashed()->lockForUpdate()->find($this->comment_id);
        $generation = self::query()->lockForUpdate()->findOrFail($this->getKey());

        if ($generation->status !== 'submitted') {
            return false;
        }

        $latest = self::query()
            ->where('comment_id', $generation->comment_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        if ($comment === null || $comment->trashed() || $comment->state !== 'pending' || $latest !== $generation->id) {
            $generation->update(['status' => 'superseded']);

            return false;
        }

        if ($status === 'succeeded') {
            $comment->update(['body' => $text, 'state' => 'draft', 'author_id' => null]);
        } else {
            $comment->update(['state' => 'failed']);
        }

        $generation->update(['status' => $status, 'error' => $error]);
        $this->setRawAttributes($generation->getAttributes(), true);

        return true;
    }
}

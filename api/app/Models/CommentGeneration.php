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
}

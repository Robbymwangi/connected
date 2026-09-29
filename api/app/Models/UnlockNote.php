<?php

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* An append-only audit log, not synchronisable in the full sense: written
   once by ADMIN, online, and a device only ever reads it as part of an
   assessment's history. No version and no SoftDeletes, matching the
   migration; UPDATED_AT is null because the table has no updated_at column,
   nothing ever revises a row here after it's written.
   docs/spec/data-model.md (#34), Conventions. */
#[Fillable(['id', 'institution_id', 'assessment_id', 'user_id', 'note'])]
class UnlockNote extends Model
{
    use HasUuidv7;

    public const UPDATED_AT = null;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

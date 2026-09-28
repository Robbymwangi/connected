<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* One row per subject a user moderates. "Subject head" and "head of
   department" are titles over the same row shape (#36); a head of
   department moderating three subjects holds three rows.
   docs/spec/data-model.md (#34). */
#[Fillable(['id', 'institution_id', 'user_id', 'subject_id'])]
class SubjectModeration extends Model
{
    use Syncable;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}

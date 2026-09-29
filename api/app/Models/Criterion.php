<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/* One row per line of a subject's rubric (frontend/src/fixtures/rubrics.ts).
   docs/spec/data-model.md (#34). $table is explicit rather than left to
   Eloquent's pluralizer: "criterion" -> "criteria" is exactly the kind of
   irregular plural worth not trusting silently. */
#[Fillable(['id', 'institution_id', 'subject_id', 'name', 'max_score'])]
class Criterion extends Model
{
    use Syncable;

    protected $table = 'criteria';

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/* One row is one sitting of one subject in one stream. status only ever
   stores scheduled, finalized, and reports-generated, the three states
   something actually writes: in-progress and complete are derived from the
   grid's own completeness every time it's read (docs/spec/workflow.md,
   #37), never a value set here. created_by scopes lifecycle actions (edit,
   finalize, unlock) to the creator or an assigned teacher (#36 Option B).
   docs/spec/data-model.md (#34). */
#[Fillable([
    'id', 'institution_id', 'class_id', 'subject_id', 'name', 'term', 'year',
    'date', 'status', 'created_by', 'finalized_at', 'finalized_by',
])]
class Assessment extends Model
{
    use BelongsToInstitution, Syncable;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'finalized_at' => 'datetime',
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function unlockNotes(): HasMany
    {
        return $this->hasMany(UnlockNote::class);
    }
}

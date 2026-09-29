<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Which subjects a class offers; CreateAssessmentDialog.tsx's class picker
   already filters on this. docs/spec/data-model.md (#34). A real model of
   its own, not a bare pivot, since it carries its own id, institution_id,
   version and soft delete like every other synchronisable row. */
#[Fillable(['id', 'institution_id', 'class_id', 'subject_id'])]
class ClassSubject extends Model
{
    use BelongsToInstitution, Syncable;

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
}

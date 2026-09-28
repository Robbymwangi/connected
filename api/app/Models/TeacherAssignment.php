<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Replaces the frontend's subjectsByStream JSON with a real, queryable
   table: the fact that scopes assessment lifecycle actions (edit, finalize,
   unlock) to a teacher who teaches that class and subject (#36 Option B).
   Never gates grading or assessment creation, which stay unrestricted.
   docs/spec/data-model.md (#34). */
#[Fillable(['id', 'institution_id', 'user_id', 'class_id', 'subject_id'])]
class TeacherAssignment extends Model
{
    use Syncable;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

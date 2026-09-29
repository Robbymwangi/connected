<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/* A table, not the frontend's fixed three-item list: criteria and the
   subject_moderations grants both reference it, and a school's subject list
   is exactly the kind of thing an institution should be able to grow.
   docs/spec/data-model.md (#34). Reached by a device only by pull; it never
   proposes a change to a subject. */
#[Fillable(['id', 'institution_id', 'name'])]
class Subject extends Model
{
    use Syncable;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(Criterion::class);
    }

    /* Not a belongsToMany: see the matching note on SchoolClass::classSubjects().
       Caught by CodeRabbit's review of #40. */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }

    public function subjectModerations(): HasMany
    {
        return $this->hasMany(SubjectModeration::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }
}

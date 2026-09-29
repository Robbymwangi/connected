<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* One row per student per year; chosen over a plain class_id on students for
   the years this school runs past a single cohort: a student's class in a
   prior year stays answerable without reconstructing it from marks.
   docs/spec/data-model.md (#34), pre-migration decision 7. */
#[Fillable(['id', 'institution_id', 'student_id', 'class_id', 'year'])]
class Enrolment extends Model
{
    use BelongsToInstitution, Syncable;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* One row per student per assessment, matching fixtures/results.ts's
   ResultRecord exactly; written by the server when an assessment finalizes.
   docs/spec/data-model.md (#34). */
#[Fillable(['id', 'institution_id', 'assessment_id', 'student_id', 'total', 'max', 'level'])]
class Result extends Model
{
    use BelongsToInstitution, Syncable;

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

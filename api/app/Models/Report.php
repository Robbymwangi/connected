<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* One per student per assessment, matching results and comments: a term
   report card is simply the report for whichever assessment is named "End
   of Term," not a different kind of record. docs/spec/data-model.md (#34). */
#[Fillable(['id', 'institution_id', 'assessment_id', 'student_id', 'generated_at', 's3_key'])]
class Report extends Model
{
    use BelongsToInstitution, Syncable;

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

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

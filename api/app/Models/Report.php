<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* A student's report card for one term (ADR 0012): one per student per term, a later term's card
   a new row, never an edit of an earlier one. The row's existence is the generated card;
   generated_at and s3_key are set together by the job that renders it. docs/spec/data-model.md. */
#[Fillable(['id', 'institution_id', 'student_id', 'year', 'term', 'generated_at', 's3_key'])]
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

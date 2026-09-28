<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* One per student per assessment, dispatched on finalize
   (docs/spec/workflow.md, #37 owns the lifecycle; this model only fixes the
   grain). docs/spec/data-model.md (#34). */
#[Fillable(['id', 'institution_id', 'assessment_id', 'student_id', 'author_id', 'body', 'state'])]
class Comment extends Model
{
    use Syncable;

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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/* The remark on a student's report card: one per student per term (ADR 0012), created pending by
   the term close with no author and no body. States: pending, draft, accepted, failed. A device reads
   it by pull; edit, accept, and regenerate are commands (docs/spec/workflow.md, Comments).
   docs/spec/data-model.md. */
#[Fillable(['id', 'institution_id', 'student_id', 'year', 'term', 'term_closure_id', 'author_id', 'body', 'state'])]
class Comment extends Model
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

    public function termClosure(): BelongsTo
    {
        return $this->belongsTo(TermClosure::class)->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(CommentGeneration::class);
    }
}

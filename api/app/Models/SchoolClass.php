<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/* Named SchoolClass, not Class: `class` is a reserved word in PHP and can't
   be a class name, so $table is explicit rather than left to the pluralizer.
   grade is a column, not derived from the stream name; class_teacher_id is
   the homeStream fact from fixtures/teachers.ts turned around, attention
   only, never a capability (#36). docs/spec/data-model.md (#34). */
#[Fillable(['id', 'institution_id', 'grade', 'stream', 'class_teacher_id'])]
class SchoolClass extends Model
{
    use Syncable;

    protected $table = 'classes';

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'class_teacher_id');
    }

    /* Not a belongsToMany: Laravel's pivot writers (attach/sync/detach)
       insert and delete class_subjects rows directly, bypassing
       ClassSubject's own id, institution_id, version, and soft delete
       entirely. A read through this hasMany and ClassSubject::create() for
       writes are the only supported paths (docs/spec/data-model.md, #34).
       Caught by CodeRabbit's review of #40. */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class, 'class_id');
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'class_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class, 'class_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'class_id');
    }
}

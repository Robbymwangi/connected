<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/* Always server- or admin-created, never offline; is_admin and the
   subject_moderations grants are the only capabilities (#36's "layered, not
   exclusive" decision). deactivated_at is deliberately not a soft delete: a
   deactivated account still has to resolve to a name wherever it's
   referenced, so it stays synced and visible and just can't authenticate or
   act. docs/spec/data-model.md (#34).

   No Notifiable: that trait's notify()/notifications() are Laravel's
   push-channel system, and AGENTS.md is explicit that this app's
   notifications are synced job-status rows the device already holds, not
   push infrastructure. The domain relation below is a plain hasMany to our
   own Notification model instead. */
#[Fillable(['id', 'institution_id', 'name', 'email', 'password', 'is_admin', 'deactivated_at'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToInstitution, HasApiTokens, HasFactory, Syncable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }

    public function subjectModerations(): HasMany
    {
        return $this->hasMany(SubjectModeration::class);
    }

    public function classesAsClassTeacher(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'class_teacher_id');
    }

    public function createdAssessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'created_by');
    }

    public function finalizedAssessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'finalized_by');
    }

    public function editedMarks(): HasMany
    {
        return $this->hasMany(Mark::class, 'last_edited_by');
    }

    public function authoredComments(): HasMany
    {
        return $this->hasMany(Comment::class, 'author_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function unlockNotes(): HasMany
    {
        return $this->hasMany(UnlockNote::class);
    }
}

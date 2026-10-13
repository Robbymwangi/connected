<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/* Always server- or admin-created, never offline. Every account is exactly one kind (ADR 0014):
   a teacher signs in to the app only, an admin to the panel only. A teacher's capabilities beyond
   grading are its subject_moderations (HOD) and the Director of Studies grant; an admin holds
   neither. deactivated_at is deliberately not a soft delete: a
   deactivated account still has to resolve to a name wherever it's
   referenced, so it stays synced and visible and just can't authenticate or
   act. docs/spec/data-model.md (#34).

   No Notifiable: that trait's notify()/notifications() are Laravel's
   push-channel system, and AGENTS.md is explicit that this app's
   notifications are synced job-status rows the device already holds, not
   push infrastructure. The domain relation below is a plain hasMany to our
   own Notification model instead. */
#[Fillable(['id', 'institution_id', 'name', 'email', 'kind', 'directs_studies', 'password', 'deactivated_at'])]
#[Hidden(['password'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToInstitution, HasApiTokens, HasFactory, Syncable;

    public const TEACHER = 'teacher';

    public const ADMIN = 'admin';

    /** The database defaults, so an account made in code knows its kind before it is reloaded. */
    protected $attributes = [
        'kind' => self::TEACHER,
        'directs_studies' => false,
    ];

    /* Deactivating an account revokes every device token it holds, in the same save (build plan
       5.5): the next API call with an old token is a 401 at once, not whenever EnsureAccountIsActive
       next looks. A model event, so it fires on a User save; a query-level bulk update fires none,
       which is why every deactivation goes through deactivate() below. */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('deactivated_at') && $user->deactivated_at !== null) {
                $user->tokens()->delete();
            }
        });
    }

    /** Whether this is the institution's only active administrator, whom no one may deactivate or demote. */
    public function isLastActiveAdmin(): bool
    {
        return $this->isAdmin() && $this->deactivated_at === null
            && ! self::query()->whereKeyNot($this->getKey())->where('kind', self::ADMIN)->whereNull('deactivated_at')->exists();
    }

    /* The one way an account is deactivated (build plan 5.5). Refused for oneself, so an
       administrator cannot lock themselves out mid-session, and for the last active administrator,
       so the school always keeps someone who can reactivate others. */
    public function deactivate(User $by): void
    {
        if ($by->is($this)) {
            throw new \DomainException('You cannot deactivate your own account.');
        }

        if ($this->isLastActiveAdmin()) {
            throw new \DomainException('This is the only active administrator; add another administrator first.');
        }

        $this->update(['deactivated_at' => now()]);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'directs_studies' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->kind === self::ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->kind === self::TEACHER;
    }

    /** A teacher holding the Director of Studies grant (ADR 0014, decision 2). */
    public function directsStudies(): bool
    {
        return $this->isTeacher() && $this->directs_studies;
    }

    /* The admin panel's one gate (ADR 0009, decision 3; ADR 0014, decision 1): an admin account
       that is active. Filament asks on every request, not only at login, so a session open when
       the account is deactivated is refused on its next request; and a refusal reads the same as a
       wrong password from outside. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() && $this->deactivated_at === null;
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

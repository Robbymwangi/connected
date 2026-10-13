<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class TermClosurePolicy
{
    /**
     * Closing a stream's term is for a teacher assigned to any subject of that stream, or a Director of
     * Studies. ADR 0014 makes it the DOS's alone; the assigned teacher keeps it until the roles merge
     * request gives the DOS a screen for it. An admin account never closes a term.
     */
    public function create(User $user, SchoolClass $schoolClass): bool
    {
        if ($user->institution_id !== $schoolClass->institution_id
            || $user->deactivated_at !== null
            || $user->trashed()) {
            return false;
        }

        if (! $user->isTeacher()) {
            return false;
        }

        return $user->directsStudies()
            || $user->teacherAssignments()->where('class_id', $schoolClass->id)->exists();
    }
}

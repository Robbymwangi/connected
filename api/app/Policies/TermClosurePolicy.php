<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class TermClosurePolicy
{
    /**
     * Closing a stream's term is for a teacher assigned to any subject of that stream, or an admin.
     * Being its class teacher grants nothing (docs/spec/access-model.md, Class teacher).
     */
    public function create(User $user, SchoolClass $schoolClass): bool
    {
        if ($user->institution_id !== $schoolClass->institution_id
            || $user->deactivated_at !== null
            || $user->trashed()) {
            return false;
        }

        return $user->is_admin
            || $user->teacherAssignments()->where('class_id', $schoolClass->id)->exists();
    }
}

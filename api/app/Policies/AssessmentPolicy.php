<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    /**
     * Allow any authenticated user to create assessments; tenancy is enforced separately.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Allow any authenticated user to update assessments; tenancy is enforced separately.
     */
    public function update(User $user, Assessment $assessment): bool
    {
        return true;
    }

    public function finalize(User $user, Assessment $assessment): bool
    {
        if ($user->institution_id !== $assessment->institution_id
            || $user->deactivated_at !== null
            || $user->trashed()) {
            return false;
        }

        return $user->id === $assessment->created_by
            || $user->teacherAssignments()
                ->where('class_id', $assessment->class_id)
                ->where('subject_id', $assessment->subject_id)
                ->exists();
    }

    public function unlock(User $user, Assessment $assessment): bool
    {
        return $user->institution_id === $assessment->institution_id
            && $user->is_admin
            && $user->deactivated_at === null
            && ! $user->trashed();
    }
}

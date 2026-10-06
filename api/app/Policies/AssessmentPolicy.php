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
     * Editing an existing assessment is scoped to whoever is answerable for it
     * (docs/spec/access-model.md): its creator, or a teacher assigned to its class
     * and subject, the same rule as finalizing. Creating and grading stay open.
     */
    public function update(User $user, Assessment $assessment): bool
    {
        return $this->isAnswerableFor($user, $assessment);
    }

    public function finalize(User $user, Assessment $assessment): bool
    {
        return $this->isAnswerableFor($user, $assessment);
    }

    private function isAnswerableFor(User $user, Assessment $assessment): bool
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

<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    /**
     * Any teacher account may create assessments; an admin account never does (ADR 0014). Tenancy is enforced separately.
     */
    public function create(User $user): bool
    {
        return $user->isTeacher();
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
        if (! $user->isTeacher()
            || $user->institution_id !== $assessment->institution_id
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

    /* Unlocking belongs to the Director of Studies (ADR 0014, decision 3), and to the HOD of the
       assessment's subject once the app has an unlock screen. Until that merge request, the admin
       panel's unlock action is the only screen that can do it, so an admin keeps it there; that
       half is removed with the panel's action. */
    public function unlock(User $user, Assessment $assessment): bool
    {
        return $user->institution_id === $assessment->institution_id
            && ($user->directsStudies() || $user->isAdmin())
            && $user->deactivated_at === null
            && ! $user->trashed();
    }
}

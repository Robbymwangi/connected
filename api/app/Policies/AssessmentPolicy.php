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

    /* Unlocking is an academic decision, made in the app (ADR 0014, decision 3): by a Director of
       Studies, for any assessment of the institution, or by the HOD of the assessment's subject,
       that is a teacher who moderates it. Never by an admin account, and not by whoever ran the
       sitting as such: the creator or an assigned teacher unlocks only if they also hold one of
       the two. */
    public function unlock(User $user, Assessment $assessment): bool
    {
        return $user->isTeacher()
            && $user->institution_id === $assessment->institution_id
            && $user->deactivated_at === null
            && ! $user->trashed()
            && ($user->directsStudies()
                || $user->subjectModerations()->where('subject_id', $assessment->subject_id)->exists());
    }
}

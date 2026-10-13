<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class TermClosurePolicy
{
    /**
     * Closing a stream's term starts its report cards, which is the exams office's decision, not
     * each subject teacher's (ADR 0014, decision 5): a Director of Studies only. An admin account
     * never closes a term, and neither assignment nor being class teacher grants it.
     */
    public function create(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isTeacher()
            && $user->directsStudies()
            && $user->institution_id === $schoolClass->institution_id
            && $user->deactivated_at === null
            && ! $user->trashed();
    }
}

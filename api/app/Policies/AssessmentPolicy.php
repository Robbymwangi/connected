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
}

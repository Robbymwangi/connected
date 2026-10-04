<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return true;
    }
}

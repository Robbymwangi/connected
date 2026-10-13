<?php

namespace App\Policies;

use App\Models\Mark;
use App\Models\User;

class MarkPolicy
{
    /**
     * Any teacher account may grade; an admin account never does (ADR 0014). Tenancy is enforced separately.
     */
    public function create(User $user): bool
    {
        return $user->isTeacher();
    }

    /**
     * Any teacher account may grade; an admin account never does (ADR 0014). Tenancy is enforced separately.
     */
    public function update(User $user, Mark $mark): bool
    {
        return $user->isTeacher();
    }
}

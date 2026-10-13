<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassPolicy
{
    /**
     * Allow only admin accounts, in the panel (ADR 0014), to create classes.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Allow only admin accounts, in the panel (ADR 0014), to update classes.
     */
    public function update(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isAdmin();
    }
}

<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassPolicy
{
    /**
     * Allow only administrators to create classes.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Allow only administrators to update classes.
     */
    public function update(User $user, SchoolClass $schoolClass): bool
    {
        return (bool) $user->is_admin;
    }
}

<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Allow only administrators to create teacher accounts.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Allow only administrators to update teacher accounts.
     */
    public function update(User $user, User $teacher): bool
    {
        return (bool) $user->is_admin;
    }
}

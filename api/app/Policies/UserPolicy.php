<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Allow only admin accounts, in the panel (ADR 0014), to create teacher accounts.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Allow only admin accounts, in the panel (ADR 0014), to update teacher accounts.
     */
    public function update(User $user, User $teacher): bool
    {
        return $user->isAdmin();
    }
}

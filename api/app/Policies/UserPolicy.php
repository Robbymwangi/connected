<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, User $teacher): bool
    {
        return (bool) $user->is_admin;
    }
}

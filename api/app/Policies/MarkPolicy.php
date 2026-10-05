<?php

namespace App\Policies;

use App\Models\Mark;
use App\Models\User;

class MarkPolicy
{
    /**
     * Allow any authenticated user to create marks; tenancy is enforced separately.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Allow any authenticated user to update marks; tenancy is enforced separately.
     */
    public function update(User $user, Mark $mark): bool
    {
        return true;
    }
}

<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassPolicy
{
    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, SchoolClass $schoolClass): bool
    {
        return (bool) $user->is_admin;
    }
}

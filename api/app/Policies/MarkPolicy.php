<?php

namespace App\Policies;

use App\Models\Mark;
use App\Models\User;

class MarkPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Mark $mark): bool
    {
        return true;
    }
}

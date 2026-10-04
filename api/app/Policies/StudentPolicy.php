<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, Student $student): bool
    {
        return (bool) $user->is_admin;
    }
}

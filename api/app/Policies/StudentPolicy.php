<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    /**
     * Allow only admin accounts, in the panel (ADR 0014), to create students.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Allow only admin accounts, in the panel (ADR 0014), to update students.
     */
    public function update(User $user, Student $student): bool
    {
        return $user->isAdmin();
    }
}

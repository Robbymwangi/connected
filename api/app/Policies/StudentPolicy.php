<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    /**
     * Allow only administrators to create students.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Allow only administrators to update students.
     */
    public function update(User $user, Student $student): bool
    {
        return (bool) $user->is_admin;
    }
}

<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

class SchoolPolicy
{
    public function view(User $user, School $school): bool
    {
        return (int) $user->school_id === $school->id && $user->can('school.view');
    }

    public function update(User $user, School $school): bool
    {
        return (int) $user->school_id === $school->id && $user->can('school.update');
    }
}

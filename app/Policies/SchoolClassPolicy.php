<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('class.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('class.manage');
    }

    public function view(User $user, SchoolClass $schoolClass): bool
    {
        return (int) $user->school_id === (int) $schoolClass->school_id && $this->viewAny($user);
    }

    public function update(User $user, SchoolClass $schoolClass): bool
    {
        return $this->view($user, $schoolClass) && $user->can('class.manage');
    }
}

<?php

namespace App\Policies;

use App\Models\Guardian;
use App\Models\User;

class GuardianPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('student.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('student.update');
    }

    public function view(User $user, Guardian $guardian): bool
    {
        return (int) $user->school_id === (int) $guardian->school_id && $this->viewAny($user);
    }

    public function update(User $user, Guardian $guardian): bool
    {
        return $this->view($user, $guardian) && $user->can('student.update');
    }
}

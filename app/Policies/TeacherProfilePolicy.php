<?php

namespace App\Policies;

use App\Models\TeacherProfile;
use App\Models\User;

class TeacherProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('teacher.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('teacher.manage');
    }

    public function view(User $user, TeacherProfile $profile): bool
    {
        return (int) $user->school_id === (int) $profile->school_id && $this->viewAny($user);
    }

    public function update(User $user, TeacherProfile $profile): bool
    {
        return $this->view($user, $profile) && $user->can('teacher.manage');
    }
}

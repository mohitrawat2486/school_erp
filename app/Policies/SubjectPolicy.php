<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('subject.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('subject.manage');
    }

    public function view(User $user, Subject $subject): bool
    {
        return (int) $user->school_id === (int) $subject->school_id && $this->viewAny($user);
    }

    public function update(User $user, Subject $subject): bool
    {
        return $this->view($user, $subject) && $user->can('subject.manage');
    }
}

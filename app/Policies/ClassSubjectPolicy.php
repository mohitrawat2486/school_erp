<?php

namespace App\Policies;

use App\Models\ClassSubject;
use App\Models\User;

class ClassSubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('subject.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('subject.manage');
    }

    public function view(User $user, ClassSubject $allocation): bool
    {
        return (int) $user->school_id === (int) $allocation->school_id && $this->viewAny($user);
    }

    public function update(User $user, ClassSubject $allocation): bool
    {
        return $this->view($user, $allocation) && $user->can('subject.manage');
    }
}

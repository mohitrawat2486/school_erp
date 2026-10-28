<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('student.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('student.create');
    }

    public function view(User $user, Student $student): bool
    {
        return (int) $user->school_id === (int) $student->school_id && $this->viewAny($user);
    }

    public function update(User $user, Student $student): bool
    {
        return $this->view($user, $student) && $user->can('student.update');
    }
}

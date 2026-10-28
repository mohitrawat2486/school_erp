<?php

namespace App\Policies;

use App\Models\TeacherAssignment;
use App\Models\User;

class TeacherAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('teacher_assignment.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('teacher_assignment.manage');
    }

    public function view(User $user, TeacherAssignment $assignment): bool
    {
        return (int) $user->school_id === (int) $assignment->school_id && $this->viewAny($user);
    }

    public function update(User $user, TeacherAssignment $assignment): bool
    {
        return $this->view($user, $assignment) && $user->can('teacher_assignment.manage');
    }
}

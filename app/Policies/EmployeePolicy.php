<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employee.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('employee.create');
    }

    public function view(User $user, Employee $employee): bool
    {
        return (int) $user->school_id === (int) $employee->school_id && $this->viewAny($user);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee) && $user->can('employee.update');
    }
}

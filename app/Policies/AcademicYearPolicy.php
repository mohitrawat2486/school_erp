<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;

class AcademicYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('academic_year.view');
    }

    public function create(User $user): bool
    {
        return $user->can('academic_year.view') && $user->can('academic_year.create');
    }

    public function view(User $user, AcademicYear $year): bool
    {
        return (int) $user->school_id === (int) $year->school_id && $this->viewAny($user);
    }

    public function update(User $user, AcademicYear $year): bool
    {
        return $this->view($user, $year) && $user->can('academic_year.update');
    }

    public function activate(User $user, AcademicYear $year): bool
    {
        return $this->view($user, $year) && $user->can('academic_year.activate');
    }

    public function close(User $user, AcademicYear $year): bool
    {
        return $this->update($user, $year);
    }

    public function archive(User $user, AcademicYear $year): bool
    {
        return $this->update($user, $year);
    }

    public function delete(User $user, AcademicYear $year): bool
    {
        return $this->update($user, $year);
    }
}

<?php

namespace App\Policies;

use App\Enums\AcademicYearStatus;
use App\Enums\EnrollmentStatus;
use App\Models\StudentEnrollment;
use App\Models\User;

class StudentEnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('student.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('student.update');
    }

    public function view(User $user, StudentEnrollment $enrollment): bool
    {
        return (int) $user->school_id === (int) $enrollment->school_id && $this->viewAny($user);
    }

    public function update(User $user, StudentEnrollment $enrollment): bool
    {
        return in_array($enrollment->status, [EnrollmentStatus::Active, EnrollmentStatus::Inactive], true) && $this->view($user, $enrollment) && $user->can('student.update') && $enrollment->academicYear && $enrollment->academicYear->status === AcademicYearStatus::Active && ! $enrollment->academicYear->trashed();
    }
}

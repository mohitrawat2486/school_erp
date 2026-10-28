<?php

namespace App\Policies;

use App\Models\StudentMark;
use App\Models\User;

class StudentMarkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('marks.view') && ($user->can('marks.verify') || $user->can('marks.lock') || $user->can('marks.unlock'));
    }

    public function view(User $user, StudentMark $mark): bool
    {
        return (int) $user->school_id === (int) $mark->school_id && $this->viewAny($user);
    }

    public function transition(User $user, StudentMark $mark, string $action): bool
    {
        return $this->view($user, $mark) && in_array($action, ['verify', 'lock', 'unlock'], true) && $user->can('marks.'.$action);
    }

    public function verify(User $user, StudentMark $mark): bool
    {
        return $this->transition($user, $mark, 'verify') && $this->available($mark, 'SUBMITTED');
    }

    public function lock(User $user, StudentMark $mark): bool
    {
        return $this->transition($user, $mark, 'lock') && $this->available($mark, 'VERIFIED');
    }

    public function unlock(User $user, StudentMark $mark): bool
    {
        return $this->transition($user, $mark, 'unlock') && $this->available($mark, 'LOCKED');
    }

    private function available(StudentMark $mark, string $status): bool
    {
        return $mark->status->value === $status && $mark->exam->status->value === 'MARKS_ENTRY' && $mark->exam->academicYear->status->value === 'ACTIVE';
    }
}

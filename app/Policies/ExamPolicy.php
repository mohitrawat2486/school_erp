<?php

namespace App\Policies;

use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\User;
use App\Support\ExamSetup;

class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('exam.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('exam.create');
    }

    public function view(User $user, Exam $exam): bool
    {
        return (int) $user->school_id === (int) $exam->school_id && $this->viewAny($user);
    }

    public function update(User $user, Exam $exam): bool
    {
        return $this->manage($user, $exam) && $exam->status === ExamStatus::Draft;
    }

    public function manage(User $user, Exam $exam): bool
    {
        return $this->view($user, $exam) && $user->can('exam.update') && ExamSetup::writable($exam->academicYear);
    }
}

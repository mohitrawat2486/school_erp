<?php

namespace App\Policies;

use App\Models\ExamSubject;
use App\Models\User;

class ExamSubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('exam.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('exam.update');
    }

    public function view(User $user, ExamSubject $record): bool
    {
        return (int) $user->school_id === (int) $record->school_id && $this->viewAny($user);
    }

    public function update(User $user, ExamSubject $record): bool
    {
        return $this->view($user, $record) && $record->exam && $user->can('update', $record->exam);
    }
}

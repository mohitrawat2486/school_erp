<?php

namespace App\Policies;

use App\Models\ExamTerm;
use App\Models\User;
use App\Support\ExamSetup;

class ExamTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('exam.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('exam.create');
    }

    public function view(User $user, ExamTerm $term): bool
    {
        return (int) $user->school_id === (int) $term->school_id && $this->viewAny($user);
    }

    public function update(User $user, ExamTerm $term): bool
    {
        return $this->view($user, $term) && $user->can('exam.update') && ExamSetup::writable($term->academicYear);
    }
}

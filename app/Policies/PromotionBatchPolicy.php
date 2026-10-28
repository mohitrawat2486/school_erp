<?php

namespace App\Policies;

use App\Models\PromotionBatch;
use App\Models\User;

class PromotionBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('student.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('student.update');
    }

    public function view(User $user, PromotionBatch $batch): bool
    {
        return (int) $user->school_id === (int) $batch->school_id && $this->viewAny($user);
    }
}

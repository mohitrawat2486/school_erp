<?php

namespace App\Policies;

use App\Models\StudentResult;
use App\Models\User;

class StudentResultPolicy
{
    public function view(User $u, StudentResult $r): bool
    {
        return (int) $u->school_id === (int) $r->school_id && $u->can('result.view') && $r->resultBatch->status === 'PUBLISHED';
    }
}

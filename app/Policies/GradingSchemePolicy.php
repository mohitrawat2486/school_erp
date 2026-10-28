<?php

namespace App\Policies;

use App\Models\GradingScheme;
use App\Models\User;

class GradingSchemePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('exam.view');
    }

    public function create(User $u): bool
    {
        return $this->viewAny($u) && $u->can('exam.update');
    }

    public function view(User $u, GradingScheme $s): bool
    {
        return (int) $u->school_id === (int) $s->school_id && $this->viewAny($u);
    }

    public function manage(User $u, GradingScheme $s): bool
    {
        return $this->view($u, $s) && $u->can('exam.update');
    }

    public function update(User $u, GradingScheme $s): bool
    {
        return $this->manage($u, $s) && $s->status->value === 'INACTIVE';
    }
}

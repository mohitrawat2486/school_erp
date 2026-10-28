<?php

namespace App\Policies;

use App\Models\User;
use App\Support\CurrentSchool;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('rbac.view');
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor) && $actor->can('rbac.manage');
    }

    public function view(User $actor, User $target): bool
    {
        return (int) $target->school_id === app(CurrentSchool::class)->id() && $this->viewAny($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->view($actor, $target) && $actor->can('rbac.manage');
    }
}

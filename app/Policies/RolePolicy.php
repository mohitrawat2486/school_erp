<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('rbac.view');
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor) && $actor->can('rbac.manage');
    }

    public function view(User $actor, Role $role): bool
    {
        return (int) $actor->school_id === (int) $role->school_id && $this->viewAny($actor);
    }

    public function update(User $actor, Role $role): bool
    {
        return $this->view($actor, $role) && $actor->can('rbac.manage');
    }
}

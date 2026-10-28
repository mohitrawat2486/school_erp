<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\User;

class SectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('section.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('section.manage');
    }

    public function view(User $user, Section $section): bool
    {
        return (int) $user->school_id === (int) $section->school_id && $this->viewAny($user);
    }

    public function update(User $user, Section $section): bool
    {
        return $this->view($user, $section) && $user->can('section.manage');
    }
}

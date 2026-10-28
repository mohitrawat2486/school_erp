<?php

namespace App\Services;

use App\Models\School;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RbacGuard
{
    public function lockAndAuthorize(User $actor): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
        $fresh = $actor->fresh();
        abort_unless($fresh, 403);
        Gate::forUser($fresh)->authorize('rbac.view');
        Gate::forUser($fresh)->authorize('rbac.manage');
    }

    public function ensureManagerRemains(): void
    {
        $schoolId = app(CurrentSchool::class)->id();
        $query = DB::table('users')->where('users.school_id', $schoolId)->where('users.is_active', true);
        foreach (['rbac.view', 'rbac.manage'] as $permission) {
            $query->whereExists(function (Builder $sub) use ($schoolId, $permission) {
                $sub->selectRaw('1')->from('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->whereColumn('user_roles.user_id', 'users.id')->where('user_roles.school_id', $schoolId)->where('roles.school_id', $schoolId)->where('permissions.name', $permission);
            });
        }
        if (! $query->exists()) {
            throw ValidationException::withMessages(['access' => 'Keep at least one active account with both RBAC view and manage permissions.']);
        }
    }
}

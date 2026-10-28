<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccessManager
{
    public function assignRoles(User $actor, User $target, array $roleIds): void
    {
        Gate::forUser($actor)->authorize('rbac.manage');
        abort_unless((int) $target->school_id === app(CurrentSchool::class)->id(), 403);
        DB::transaction(function () use ($actor, $target, $roleIds) {
            app(RbacGuard::class)->lockAndAuthorize($actor);
            User::where('school_id', app(CurrentSchool::class)->id())->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $ids = array_values(array_unique(array_map('intval', $roleIds)));
            if (Role::whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['roles' => 'Invalid school roles.']);
            }
            $old = DB::table('user_roles')->where('school_id', $target->school_id)->where('user_id', $target->id)->pluck('role_id')->all();
            DB::table('user_roles')->where('school_id', $target->school_id)->where('user_id', $target->id)->delete();
            foreach ($ids as $id) {
                DB::table('user_roles')->insert(['school_id' => $target->school_id, 'user_id' => $target->id, 'role_id' => $id]);
            }
            app(RbacGuard::class)->ensureManagerRemains();
            app(AuditLogger::class)->record($actor, 'USER_ROLES_CHANGED', 'user', $target->id, ['role_ids' => $old], ['role_ids' => $ids]);
        });
    }

    public function setPermissions(User $actor, Role $role, array $permissionIds): void
    {
        Gate::forUser($actor)->authorize('rbac.manage');
        abort_unless((int) $role->school_id === app(CurrentSchool::class)->id(), 403);
        DB::transaction(function () use ($actor, $role, $permissionIds) {
            app(RbacGuard::class)->lockAndAuthorize($actor);
            Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $ids = array_values(array_unique(array_map('intval', $permissionIds)));
            if (DB::table('permissions')->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['permissions' => 'Invalid permissions.']);
            }
            $old = DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission_id')->all();
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            foreach ($ids as $id) {
                DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $id]);
            }
            app(RbacGuard::class)->ensureManagerRemains();
            app(AuditLogger::class)->record($actor, 'ROLE_PERMISSIONS_CHANGED', 'role', $role->id, ['permission_ids' => $old], ['permission_ids' => $ids]);
        });
    }

    public function saveRole(User $actor, ?Role $role, string $name, array $permissionIds): Role
    {
        return DB::transaction(function () use ($actor, $role, $name, $permissionIds) {
            app(RbacGuard::class)->lockAndAuthorize($actor);
            if ($role) {
                abort_unless((int) $role->school_id === app(CurrentSchool::class)->id(), 403);
                $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            }
            $duplicate = Role::where('name', $name);
            if ($role) {
                $duplicate->whereKeyNot($role->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['name' => 'This role name is already in use.']);
            }
            $old = $role?->only('name') ?? [];
            $creating = $role === null;
            $role ??= new Role;
            $role->name = $name;
            $role->save();
            app(AuditLogger::class)->record($actor, $creating ? 'ROLE_CREATED' : 'ROLE_UPDATED', 'role', $role->id, $old, $role->only('name'));
            $this->setPermissions($actor, $role, $permissionIds);

            return $role;
        });
    }
}

<?php

namespace App\Services;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProvisionSchool
{
    public function run(string $code, string $name, string $email): School
    {
        try {
            return DB::transaction(function () use ($code, $name, $email) {
                $user = User::where('email', $email)->lockForUpdate()->firstOrFail();
                $school = School::firstOrCreate(['code' => $code], ['name' => $name]);
                if ($user->school_id && (int) $user->school_id !== $school->id) {
                    throw ValidationException::withMessages(['school' => 'This account already belongs to another school.']);
                }
                app(CurrentSchool::class)->set($school);
                $oldSchool = $user->school_id;
                $user->school_id = $school->id;
                $user->save();
                $oldRoles = DB::table('user_roles')->where('user_id', $user->id)->pluck('role_id')->all();
                foreach (config('permissions') as $permission) {
                    DB::table('permissions')->insertOrIgnore(['name' => $permission, 'updated_at' => now(), 'created_at' => now()]);
                }
                foreach (['SUPER_ADMIN', 'SCHOOL_ADMIN', 'PRINCIPAL', 'TEACHER', 'ACCOUNTANT', 'RECEPTIONIST', 'RESULT_MANAGER'] as $name) {
                    Role::firstOrCreate(['name' => $name]);
                }
                $role = Role::where('name', 'SCHOOL_ADMIN')->firstOrFail();
                $oldPermissions = DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission_id')->all();
                foreach (DB::table('permissions')->pluck('id') as $id) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $id]);
                }
                DB::table('user_roles')->insertOrIgnore(['school_id' => $school->id, 'user_id' => $user->id, 'role_id' => $role->id]);

                app(AuditLogger::class)->record($user, 'SCHOOL_ADMIN_PROVISIONED', 'user', $user->id, ['school_id' => $oldSchool, 'role_ids' => $oldRoles, 'admin_permission_ids' => $oldPermissions], ['school_id' => $school->id, 'role_ids' => DB::table('user_roles')->where('user_id', $user->id)->pluck('role_id')->all(), 'admin_permission_ids' => DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission_id')->all()]);

                return $school;
            });
        } finally {
            app(CurrentSchool::class)->clear();
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['name' => 'student.import', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('name', 'student.import')->value('id');
        $roles = DB::table('roles')->whereIn('name', ['SCHOOL_ADMIN', 'SUPER_ADMIN'])->whereExists(function ($query) {
            $query->selectRaw('1')->from('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->whereColumn('role_permissions.role_id', 'roles.id')->where('permissions.name', 'student.create');
        })->pluck('id');
        foreach ($roles as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'student.import')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Services\UserAdministrationService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('DEMO', 'Demo School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'New Account', 'email' => 'member@example.test', 'password' => 'LongSecurePassword123!', 'password_confirmation' => 'LongSecurePassword123!', 'is_active' => '1', 'roles' => []], $overrides);
    }

    private function editPayload(User $user, array $overrides = []): array
    {
        return array_merge(['name' => $user->name, 'email' => $user->email, 'is_active' => '1', 'roles' => []], $overrides);
    }

    public function test_guests_and_accounts_without_rbac_cannot_access_admin_screens(): void
    {
        $this->get('/administration/users')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        foreach (['users', 'roles', 'permissions'] as $page) {
            $this->get('/administration/'.$page)->assertForbidden();
        }
        $this->post('/administration/users', $this->payload())->assertForbidden();
        $this->post('/administration/roles', ['name' => 'CUSTOM'])->assertForbidden();
    }

    public function test_manager_can_view_all_forms_and_permission_catalog(): void
    {
        $admin = $this->admin();
        $role = Role::where('name', 'TEACHER')->firstOrFail();
        $this->actingAs($admin);
        foreach (['users', 'users/create', 'users/'.$admin->id.'/edit', 'roles', 'roles/create', 'roles/'.$role->id.'/edit', 'permissions'] as $page) {
            $this->get('/administration/'.$page)->assertOk();
        }
        $this->get('/administration/permissions')->assertSee('rbac.manage');
    }

    public function test_creating_user_hashes_password_and_assigns_roles_with_audit(): void
    {
        $admin = $this->admin();
        $role = Role::where('name', 'TEACHER')->firstOrFail();
        $this->actingAs($admin)->post('/administration/users', $this->payload(['roles' => [$role->id]]))->assertRedirect();
        $user = User::where('email', 'member@example.test')->firstOrFail();
        $this->assertSame($admin->school_id, $user->school_id);
        $this->assertTrue(Hash::check('LongSecurePassword123!', $user->password));
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'role_id' => $role->id, 'school_id' => $admin->school_id]);
        $audit = DB::table('audit_logs')->where('action', 'USER_CREATED')->where('entity_id', $user->id)->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('LongSecurePassword123!', $audit->new_values);
        $this->assertStringNotContainsString($user->password, $audit->new_values);
    }

    public function test_account_update_preserves_blank_password_and_can_disable_then_enable(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        $hash = $member->password;
        $url = '/administration/users/'.$member->id;
        $this->actingAs($admin)->put($url, $this->editPayload($member, ['name' => 'Renamed', 'password' => '', 'password_confirmation' => '']))->assertRedirect();
        $this->assertSame($hash, $member->fresh()->password);
        $this->assertSame('Renamed', $member->fresh()->name);
        $this->put($url, $this->editPayload($member, ['is_active' => '0']))->assertRedirect();
        $this->assertFalse($member->fresh()->is_active);
        $this->put($url, $this->editPayload($member))->assertRedirect();
        $this->assertTrue($member->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_UPDATED', 'entity_id' => $member->id]);
    }

    public function test_user_validation_rejects_weak_passwords_duplicates_and_school_input(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/administration/users', $this->payload(['password' => 'weak', 'password_confirmation' => 'different', 'school_id' => 999, 'email' => $admin->email]))->assertSessionHasErrors(['password', 'email', 'school_id']);
        $this->assertDatabaseCount('users', 1);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
    }

    public function test_foreign_users_roles_and_role_ids_are_rejected(): void
    {
        $admin = $this->admin();
        $foreignUser = User::factory()->create(['name' => 'FOREIGN_SECRET_ACCOUNT']);
        app(CurrentSchool::class)->set($foreignUser->school);
        $foreignRole = Role::create(['name' => 'FOREIGN_SECRET_ROLE']);
        $this->actingAs($admin)->get('/administration/users')->assertOk()->assertDontSee('FOREIGN_SECRET_ACCOUNT');
        $this->get('/administration/roles')->assertOk()->assertDontSee('FOREIGN_SECRET_ROLE');
        $this->get('/administration/users/'.$foreignUser->id.'/edit')->assertNotFound();
        $this->put('/administration/users/'.$foreignUser->id, $this->editPayload($foreignUser))->assertNotFound();
        $this->get('/administration/roles/'.$foreignRole->id.'/edit')->assertNotFound();
        $this->put('/administration/roles/'.$foreignRole->id, ['name' => 'RENAMED'])->assertNotFound();
        $this->post('/administration/users', $this->payload(['roles' => [$foreignRole->id]]))->assertSessionHasErrors('roles.0');
        $this->assertDatabaseMissing('users', ['email' => 'member@example.test']);
    }

    public function test_view_only_admin_can_inspect_but_not_change_accounts_or_roles(): void
    {
        $admin = $this->admin();
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'rbac.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $this->actingAs($reader)->get('/administration/users')->assertOk()->assertDontSee('Add user');
        $this->get('/administration/users/'.$admin->id.'/edit')->assertOk()->assertDontSee('Save account')->assertDontSee('name="password"', false);
        $this->get('/administration/roles/'.$role->id.'/edit')->assertOk()->assertDontSee('Save role');
        $this->get('/administration/permissions')->assertOk();
        $this->get('/administration/users/create')->assertForbidden();
        $this->get('/administration/roles/create')->assertForbidden();
        $this->post('/administration/users', $this->payload())->assertForbidden();
        $this->put('/administration/users/'.$admin->id, $this->editPayload($admin))->assertForbidden();
        $this->post('/administration/roles', ['name' => 'CUSTOM'])->assertForbidden();
        $this->put('/administration/roles/'.$role->id, ['name' => 'CHANGED'])->assertForbidden();
    }

    public function test_role_creation_and_permission_changes_take_effect(): void
    {
        $admin = $this->admin();
        $permission = DB::table('permissions')->where('name', 'student.view')->value('id');
        $this->actingAs($admin)->post('/administration/roles', ['name' => 'OFFICE_READER', 'permissions' => [$permission]])->assertRedirect();
        app(CurrentSchool::class)->set($admin->school);
        $role = Role::where('name', 'OFFICE_READER')->firstOrFail();
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        app(AccessManager::class)->assignRoles($admin, $member, [$role->id]);
        $this->assertTrue($member->can('student.view'));
        $this->put('/administration/roles/'.$role->id, ['name' => 'OFFICE_REVIEWER', 'permissions' => []])->assertRedirect();
        app(CurrentSchool::class)->set($admin->school);
        $this->assertFalse($member->can('student.view'));
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'OFFICE_REVIEWER']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ROLE_CREATED', 'entity_id' => $role->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ROLE_UPDATED', 'entity_id' => $role->id]);
    }

    public function test_invalid_duplicate_role_names_and_unknown_permissions_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/administration/roles', ['name' => 'bad name', 'permissions' => [999999], 'school_id' => 999])->assertSessionHasErrors(['name', 'permissions.0', 'school_id']);
        $this->post('/administration/roles', ['name' => 'TEACHER'])->assertSessionHasErrors('name');
    }

    public function test_last_manager_cannot_remove_own_roles_or_required_permissions(): void
    {
        $admin = $this->admin();
        $role = Role::where('name', 'SCHOOL_ADMIN')->firstOrFail();
        $this->actingAs($admin)->put('/administration/users/'.$admin->id, $this->editPayload($admin))->assertSessionHasErrors('access');
        $this->assertDatabaseHas('user_roles', ['user_id' => $admin->id, 'role_id' => $role->id]);
        $this->put('/administration/roles/'.$role->id, ['name' => 'SCHOOL_ADMIN', 'permissions' => []])->assertSessionHasErrors('access');
        app(CurrentSchool::class)->set($admin->school);
        $this->assertTrue($admin->can('rbac.manage'));
        $this->assertTrue($admin->can('rbac.view'));
    }

    public function test_self_disabling_is_always_rejected(): void
    {
        $admin = $this->admin();
        $role = Role::where('name', 'SCHOOL_ADMIN')->firstOrFail();
        $this->actingAs($admin)->put('/administration/users/'.$admin->id, $this->editPayload($admin, ['is_active' => 0, 'roles' => [$role->id]]))->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_self_role_removal_is_allowed_when_another_manager_remains(): void
    {
        $admin = $this->admin();
        $role = Role::where('name', 'SCHOOL_ADMIN')->firstOrFail();
        $second = User::factory()->create(['school_id' => $admin->school_id]);
        app(AccessManager::class)->assignRoles($admin, $second, [$role->id]);
        $this->actingAs($admin)->put('/administration/users/'.$admin->id, $this->editPayload($admin))->assertRedirect('/dashboard');
        app(CurrentSchool::class)->set($admin->school);
        $this->assertFalse($admin->can('rbac.manage'));
        $this->assertTrue($second->can('rbac.manage'));
    }

    public function test_disabled_account_cannot_sign_in_or_keep_using_dashboard(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        $this->actingAs($admin)->put('/administration/users/'.$member->id, $this->editPayload($member, ['is_active' => 0]))->assertRedirect();
        $this->post('/logout');
        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($member)->get('/dashboard')->assertForbidden();
    }

    public function test_password_reset_changes_hash_rotates_remember_token_and_removes_sessions(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        $oldToken = $member->remember_token;
        DB::table('sessions')->insert(['id' => 'other-browser', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->put('/administration/users/'.$member->id, $this->editPayload($member, ['password' => 'ReplacementPassword123!', 'password_confirmation' => 'ReplacementPassword123!']))->assertRedirect();
        $this->assertTrue(Hash::check('ReplacementPassword123!', $member->fresh()->password));
        $this->assertNotSame($oldToken, $member->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser']);
    }

    public function test_failed_audit_rolls_back_user_creation_and_role_assignment(): void
    {
        $admin = $this->admin();
        $role = Role::where('name', 'TEACHER')->firstOrFail();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failure'));
        try {
            app(UserAdministrationService::class)->save($admin, null, $this->payload(['roles' => [$role->id]]));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failure', $exception->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'member@example.test']);
        $this->assertDatabaseCount('user_roles', 1);
    }
}

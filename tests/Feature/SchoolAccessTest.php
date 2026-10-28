<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SchoolAccessTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        $school = app(ProvisionSchool::class)->run('DEMO', 'Demo School', $user->email);
        app(CurrentSchool::class)->set($school);

        return $user->fresh();
    }

    public function test_account_without_school_cannot_access_dashboard_but_can_logout(): void
    {
        $user = User::factory()->create(['school_id' => null]);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_inactive_user_and_school_are_denied(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $user->is_active = true;
        $user->save();
        $user->school->update(['is_active' => false]);
        $this->actingAs($user->fresh())->get('/dashboard')->assertForbidden();
    }

    public function test_audit_requires_permission_and_isolates_schools(): void
    {
        $admin = $this->administrator();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        app(AuditLogger::class)->record($other, 'OTHER_SCHOOL_SECRET', 'user', $other->id);
        $this->actingAs($admin)->get('/administration/audit-logs')->assertOk()->assertSee('SCHOOL_ADMIN_PROVISIONED')->assertDontSee('OTHER_SCHOOL_SECRET');
        $this->actingAs($other)->get('/administration/audit-logs')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(CurrentSchool::class)->get();
    }

    public function test_scoped_lookup_does_not_resolve_foreign_role(): void
    {
        $this->administrator();
        $role = Role::firstOrFail();
        app(CurrentSchool::class)->set(School::factory()->create());
        $this->assertNull(Role::find($role->id));
    }

    public function test_queries_without_context_fail_closed(): void
    {
        app(CurrentSchool::class)->clear();
        $this->expectException(AuthorizationException::class);
        Role::all();
    }

    public function test_cross_school_writes_are_rejected(): void
    {
        $this->administrator();
        $role = Role::firstOrFail();
        app(CurrentSchool::class)->set(School::factory()->create());
        $this->expectException(AuthorizationException::class);
        $role->update(['name' => 'CHANGED']);
    }

    public function test_database_rejects_cross_school_role_assignment(): void
    {
        $this->administrator();
        $role = Role::firstOrFail();
        $other = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('user_roles')->insert(['school_id' => $role->school_id, 'user_id' => $other->id, 'role_id' => $role->id]);
    }

    public function test_role_changes_are_authorized_and_audited(): void
    {
        $admin = $this->administrator();
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'TEACHER')->firstOrFail();
        $permission = DB::table('permissions')->where('name', 'marks.enter')->value('id');
        app(AccessManager::class)->setPermissions($admin, $role, [$permission]);
        app(AccessManager::class)->assignRoles($admin, $member, [$role->id]);
        $this->assertTrue($member->can('marks.enter'));
        $this->assertFalse($member->can('rbac.manage'));
        $this->assertSame(1, AuditLog::where('action', 'USER_ROLES_CHANGED')->count());
        $this->assertSame(1, AuditLog::where('action', 'ROLE_PERMISSIONS_CHANGED')->count());
        app(AccessManager::class)->setPermissions($admin, $role, []);
        $this->assertFalse($member->can('marks.enter'));
        $this->expectException(AuthorizationException::class);
        app(AccessManager::class)->assignRoles($member, $member, []);
    }

    public function test_foreign_roles_do_not_replace_existing_roles(): void
    {
        $admin = $this->administrator();
        $original = DB::table('user_roles')->where('user_id', $admin->id)->pluck('role_id')->all();
        $other = School::factory()->create();
        app(CurrentSchool::class)->set($other);
        $foreign = Role::create(['name' => 'FOREIGN']);
        app(CurrentSchool::class)->set($admin->school);
        try {
            app(AccessManager::class)->assignRoles($admin, $admin, [$foreign->id]);
            $this->fail('Foreign role accepted.');
        } catch (ValidationException) {
            $this->assertSame($original, DB::table('user_roles')->where('user_id', $admin->id)->pluck('role_id')->all());
        }
    }

    public function test_role_change_rolls_back_when_auditing_fails(): void
    {
        $admin = $this->administrator();
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'TEACHER')->firstOrFail();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failure'));
        try {
            app(AccessManager::class)->assignRoles($admin, $member, [$role->id]);
            $this->fail('Audit failure ignored.');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('user_roles')->where('user_id', $member->id)->count());
        }
    }

    public function test_audit_redacts_secrets_and_cannot_be_changed_or_deleted(): void
    {
        $admin = $this->administrator();
        $log = app(AuditLogger::class)->record($admin, 'TEST', 'user', $admin->id, [], ['password' => 'sensitive', 'nested' => ['api_token' => 'sensitive']]);
        $this->assertSame('[REDACTED]', $log->new_values['password']);
        $this->assertSame('[REDACTED]', $log->new_values['nested']['api_token']);
        try {
            $log->update(['action' => 'TAMPER']);
            $this->fail('Audit updated.');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_provisioning_is_repeatable_and_cannot_move_an_account(): void
    {
        $admin = $this->administrator();
        app(ProvisionSchool::class)->run('DEMO', 'Demo School', $admin->email);
        $this->assertSame(1, School::count());
        $this->assertSame(1, DB::table('user_roles')->where('user_id', $admin->id)->count());
        $this->expectException(ValidationException::class);
        app(ProvisionSchool::class)->run('OTHER', 'Other School', $admin->email);
    }

    public function test_school_policy_rejects_other_schools_even_for_administrator(): void
    {
        $admin = $this->administrator();
        $this->assertTrue($admin->can('view', $admin->school));
        $this->assertTrue($admin->can('update', $admin->school));
        $this->assertFalse($admin->can('update', School::factory()->create()));
    }

    public function test_successive_requests_resolve_the_correct_school(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();
        $this->actingAs($one)->get('/dashboard')->assertOk()->assertSee($one->school->name)->assertDontSee($two->school->name);
        $this->actingAs($two)->get('/dashboard')->assertOk()->assertSee($two->school->name)->assertDontSee($one->school->name);
        $this->expectException(AuthorizationException::class);
        app(CurrentSchool::class)->get();
    }

    public function test_cross_school_role_and_permission_management_is_denied(): void
    {
        $admin = $this->administrator();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = Role::create(['name' => 'FOREIGN']);
        app(CurrentSchool::class)->set($admin->school);
        try {
            app(AccessManager::class)->assignRoles($admin, $other, []);
            $this->fail('Foreign user accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(HttpException::class);
        app(AccessManager::class)->setPermissions($admin, $foreign, []);
    }
}

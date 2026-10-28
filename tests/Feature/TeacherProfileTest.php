<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Role;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\EmployeeService;
use App\Services\ProvisionSchool;
use App\Services\TeacherProfileService;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherProfileTest extends TestCase
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

    private function employee(User $owner, array $extra = []): Employee
    {
        app(CurrentSchool::class)->set($owner->school);

        return Employee::factory()->create($extra);
    }

    private function profile(User $owner, array $extra = []): TeacherProfile
    {
        app(CurrentSchool::class)->set($owner->school);

        return TeacherProfile::factory()->create($extra);
    }

    private function data(array $extra = []): array
    {
        return array_merge(['qualification' => 'B.Ed. and M.Sc.', 'specialization' => 'Mathematics', 'experience' => '3.50'], $extra);
    }

    public function test_guest_and_accounts_without_teacher_permissions_are_denied(): void
    {
        $this->get('/teachers')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/teachers')->assertForbidden();
        $this->post('/teachers', $this->data(['employee_id' => 1]))->assertForbidden();
    }

    public function test_creation_and_editing_reuse_employee_identity_and_are_audited(): void
    {
        $admin = $this->admin();
        $employee = $this->employee($admin, ['employee_type' => 'PRINCIPAL', 'first_name' => 'Anita', 'last_name' => 'Sharma']);
        $this->actingAs($admin)->get('/teachers')->assertOk();
        $this->get('/teachers/create?employee_id='.$employee->id)->assertOk()->assertSee('Anita Sharma');
        $this->post('/teachers', $this->data(['employee_id' => $employee->id]))->assertRedirect();
        $id = DB::table('teacher_profiles')->value('id');
        $this->get('/teachers/'.$id)->assertOk()->assertSee('Anita Sharma')->assertSee('3.50');
        $this->get('/teachers/'.$id.'/edit')->assertOk();
        $this->put('/teachers/'.$id, $this->data(['specialization' => 'Physics', 'experience' => '4.25']))->assertRedirect();
        $this->assertDatabaseHas('teacher_profiles', ['id' => $id, 'school_id' => $admin->school_id, 'employee_id' => $employee->id, 'specialization' => 'Physics', 'experience' => '4.25', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'employee_type' => 'PRINCIPAL', 'first_name' => 'Anita']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TEACHER_PROFILE_CREATED', 'entity_id' => $id, 'module' => 'teachers']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TEACHER_PROFILE_UPDATED', 'entity_id' => $id]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('employees', 1);
        $this->get('/employees/'.$employee->id)->assertOk()->assertSee('View teacher profile');
    }

    public function test_profile_is_unique_and_linked_employee_cannot_be_changed(): void
    {
        $admin = $this->admin();
        $profile = $this->profile($admin);
        $other = $this->employee($admin);
        $this->actingAs($admin)->post('/teachers', $this->data(['employee_id' => $profile->employee_id]))->assertSessionHasErrors('employee_id');
        $this->put('/teachers/'.$profile->id, $this->data(['employee_id' => $other->id]))->assertSessionHasErrors('employee_id');
        $this->assertDatabaseHas('teacher_profiles', ['id' => $profile->id, 'employee_id' => $profile->employee_id]);
        $this->assertDatabaseCount('teacher_profiles', 1);
    }

    public function test_experience_and_protected_fields_are_validated(): void
    {
        $admin = $this->admin();
        $employee = $this->employee($admin);
        $this->actingAs($admin)->post('/teachers', $this->data(['employee_id' => $employee->id, 'experience' => '-1', 'school_id' => 999, 'status' => 'INACTIVE']))->assertSessionHasErrors(['experience', 'school_id', 'status']);
        foreach (['100', '1.001', 'not-numeric'] as $experience) {
            $this->post('/teachers', $this->data(['employee_id' => $employee->id, 'experience' => $experience]))->assertSessionHasErrors('experience');
        }
        $this->post('/teachers', $this->data(['employee_id' => $employee->id, 'experience' => '0']))->assertRedirect();
        $id = DB::table('teacher_profiles')->value('id');
        $this->get('/teachers/'.$id)->assertOk()->assertSee('0.00');
        $this->put('/teachers/'.$id, $this->data(['experience' => null]))->assertRedirect();
        $this->assertDatabaseHas('teacher_profiles', ['id' => $id, 'experience' => null]);
    }

    public function test_employee_picker_excludes_inactive_existing_and_foreign_records(): void
    {
        $admin = $this->admin();
        $available = $this->employee($admin, ['first_name' => 'Available', 'last_name' => 'Candidate']);
        $inactive = $this->employee($admin, ['status' => 'INACTIVE']);
        $profile = $this->profile($admin)->load('employee');
        $other = User::factory()->create();
        $foreign = $this->employee($other);
        $this->actingAs($admin)->get('/teachers/create')->assertOk()->assertSee($available->employee_number)->assertDontSee($inactive->employee_number)->assertDontSee($profile->employee->employee_number)->assertDontSee($foreign->employee_number);
        $this->get('/teachers/create?q=Available%20Candidate')->assertOk()->assertSee($available->employee_number);
        foreach ([$inactive, $profile->employee, $foreign] as $employee) {
            $this->get('/teachers/create?employee_id='.$employee->id)->assertNotFound();
        }
        $this->post('/teachers', $this->data(['employee_id' => $inactive->id]))->assertSessionHasErrors('employee_id');
        $this->post('/teachers', $this->data(['employee_id' => $foreign->id]))->assertSessionHasErrors('employee_id');
    }

    public function test_foreign_profile_endpoints_and_service_changes_are_denied(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $foreign = $this->profile($other, ['specialization' => 'FOREIGN_SECRET']);
        $url = '/teachers/'.$foreign->id;
        $this->actingAs($admin)->get('/teachers')->assertOk()->assertDontSee('FOREIGN_SECRET');
        $this->get($url)->assertNotFound();
        $this->get($url.'/edit')->assertNotFound();
        $this->put($url, $this->data())->assertNotFound();
        $this->post($url.'/activate')->assertNotFound();
        $this->post($url.'/deactivate')->assertNotFound();
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(TeacherProfileService::class)->save($admin, $foreign, $this->data());
    }

    public function test_view_only_teacher_access_cannot_mutate_or_see_private_employee_data(): void
    {
        $admin = $this->admin();
        $employee = $this->employee($admin, ['email' => 'private-personal@example.test', 'address' => 'PRIVATE_HOME_ADDRESS']);
        $profile = $this->profile($admin, ['employee_id' => $employee->id]);
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'teacher.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $this->actingAs($reader)->get('/teachers')->assertOk()->assertDontSee('Add teacher profile');
        $this->get('/teachers/'.$profile->id)->assertOk()->assertSee($employee->fullName())->assertDontSee('Edit teacher profile')->assertDontSee('View employee record')->assertDontSee('private-personal@example.test')->assertDontSee('PRIVATE_HOME_ADDRESS');
        $this->get('/teachers/create')->assertForbidden();
        $this->get('/teachers/'.$profile->id.'/edit')->assertForbidden();
        $this->post('/teachers', $this->data(['employee_id' => $employee->id]))->assertForbidden();
        $this->put('/teachers/'.$profile->id, $this->data())->assertForbidden();
        $this->post('/teachers/'.$profile->id.'/activate')->assertForbidden();
        $this->post('/teachers/'.$profile->id.'/deactivate')->assertForbidden();
    }

    public function test_teacher_manager_can_manage_profiles_without_employee_edit_permissions(): void
    {
        $admin = $this->admin();
        $employee = $this->employee($admin);
        $manager = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'TEACHER')->firstOrFail();
        $ids = DB::table('permissions')->whereIn('name', ['teacher.view', 'teacher.manage'])->pluck('id')->all();
        app(AccessManager::class)->setPermissions($admin, $role, $ids);
        app(AccessManager::class)->assignRoles($admin, $manager, [$role->id]);
        $this->actingAs($manager)->get('/teachers/create')->assertOk()->assertSee($employee->employee_number);
        $this->post('/teachers', $this->data(['employee_id' => $employee->id]))->assertRedirect();
        $this->get('/employees/'.$employee->id)->assertForbidden();
    }

    public function test_employee_deactivation_cascades_but_reactivation_is_explicit(): void
    {
        $admin = $this->admin();
        $profile = $this->profile($admin);
        $employeeId = $profile->employee_id;
        $this->actingAs($admin)->post('/employees/'.$employeeId.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('teacher_profiles', ['id' => $profile->id, 'status' => 'INACTIVE', 'qualification' => 'B.Ed.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TEACHER_PROFILE_DEACTIVATED', 'entity_id' => $profile->id]);
        $this->post('/teachers/'.$profile->id.'/activate')->assertSessionHasErrors('employee_id');
        $this->post('/employees/'.$employeeId.'/activate')->assertRedirect();
        $this->assertDatabaseHas('teacher_profiles', ['id' => $profile->id, 'status' => 'INACTIVE']);
        $this->post('/teachers/'.$profile->id.'/activate')->assertRedirect();
        $this->assertDatabaseHas('teacher_profiles', ['id' => $profile->id, 'status' => 'ACTIVE']);
        $this->post('/teachers/'.$profile->id.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TEACHER_PROFILE_ACTIVATED', 'entity_id' => $profile->id]);
        $this->delete('/teachers/'.$profile->id)->assertStatus(405);
    }

    public function test_eligibility_checks_both_profile_and_employee_status(): void
    {
        $admin = $this->admin();
        $active = $this->profile($admin);
        $inactiveProfile = $this->profile($admin, ['status' => 'INACTIVE']);
        $inactiveEmployee = $this->profile($admin);
        Employee::whereKey($inactiveEmployee->employee_id)->update(['status' => 'INACTIVE']);
        $this->assertTrue($active->isEligible());
        $this->assertFalse($inactiveProfile->isEligible());
        $this->assertFalse($inactiveEmployee->isEligible());
        $this->assertSame([$active->id], TeacherProfile::eligible()->pluck('id')->all());
    }

    public function test_teacher_listing_search_filter_and_picker_are_paginated(): void
    {
        $admin = $this->admin();
        TeacherProfile::factory()->count(23)->create(['specialization' => 'Physics']);
        Employee::factory()->count(22)->create(['first_name' => 'Eligible']);
        $this->actingAs($admin)->get('/teachers?q=Physics&status=ACTIVE')->assertOk()->assertViewHas('profiles', fn ($rows) => $rows->count() === 20 && $rows->total() === 23 && str_contains($rows->url(2), 'q=Physics'));
        $this->get('/teachers/create?q=Eligible')->assertOk()->assertViewHas('employees', fn ($rows) => $rows->count() === 20 && $rows->total() === 22 && str_contains($rows->url(2), 'q=Eligible'));
        $this->get('/teachers?status=INVALID')->assertSessionHasErrors('status');
    }

    public function test_database_enforces_one_profile_per_employee(): void
    {
        $admin = $this->admin();
        $profile = $this->profile($admin);
        $this->expectException(QueryException::class);
        DB::table('teacher_profiles')->insert(['school_id' => $admin->school_id, 'employee_id' => $profile->employee_id]);
    }

    public function test_database_rejects_cross_school_employee_relationship(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $employee = $this->employee($other);
        $this->expectException(QueryException::class);
        DB::table('teacher_profiles')->insert(['school_id' => $admin->school_id, 'employee_id' => $employee->id]);
    }

    public function test_failed_audit_rolls_back_profile_creation(): void
    {
        $admin = $this->admin();
        $employee = $this->employee($admin);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(TeacherProfileService::class)->save($admin, null, $this->data(['employee_id' => $employee->id]));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('teacher_profiles', 0);
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_failed_employee_audit_rolls_back_employee_and_profile_deactivation(): void
    {
        $admin = $this->admin();
        $profile = $this->profile($admin);
        $employee = $profile->employee;
        $auditCount = DB::table('audit_logs')->count();
        $real = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->twice()->andReturnUsing(function (...$args) use ($real) {
            if ($args[1] === 'EMPLOYEE_DEACTIVATED') {
                throw new \RuntimeException('Audit failed');
            }

            return $real->record(...$args);
        });
        try {
            app(EmployeeService::class)->setStatus($admin, $employee, EmployeeStatus::Inactive);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('teacher_profiles', ['id' => $profile->id, 'status' => 'ACTIVE']);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }
}

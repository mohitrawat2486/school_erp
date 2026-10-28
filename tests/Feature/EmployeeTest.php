<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\EmployeeService;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeTest extends TestCase
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

    private function payload(array $extra = []): array
    {
        return array_merge(['employee_number' => 'EMP/001', 'first_name' => 'Alex', 'last_name' => 'Sharma', 'employee_type' => 'ACCOUNTANT', 'joining_date' => '2020-04-01'], $extra);
    }

    private function employee(User $owner, array $extra = []): Employee
    {
        app(CurrentSchool::class)->set($owner->school);

        return Employee::factory()->create($extra);
    }

    public function test_guests_and_accounts_without_permissions_cannot_access_employees(): void
    {
        $this->get('/employees')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/employees')->assertForbidden();
        $this->post('/employees', $this->payload())->assertForbidden();
    }

    public function test_admin_can_create_read_and_update_employee_with_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/employees')->assertOk();
        $this->get('/employees/create')->assertOk()->assertSee('Add Employee');
        $this->post('/employees', $this->payload(['email' => 'office@example.test', 'date_of_birth' => '1988-05-01']))->assertRedirect();
        $id = DB::table('employees')->value('id');
        $this->get('/employees/'.$id)->assertOk()->assertSee('Alex Sharma')->assertSee('Accountant');
        $this->get('/employees/'.$id.'/edit')->assertOk();
        $this->put('/employees/'.$id, $this->payload(['first_name' => 'Updated', 'department' => 'Finance']))->assertRedirect();
        $this->assertDatabaseHas('employees', ['id' => $id, 'school_id' => $admin->school_id, 'first_name' => 'Updated', 'department' => 'Finance', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMPLOYEE_CREATED', 'entity_id' => $id, 'module' => 'employees']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMPLOYEE_UPDATED', 'entity_id' => $id]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_fields_and_direct_status_changes_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/employees', $this->payload(['employee_number' => '', 'first_name' => '', 'employee_type' => 'INVALID', 'email' => 'invalid', 'gender' => 'INVALID', 'joining_date' => 'bad', 'school_id' => 999, 'status' => 'INACTIVE', 'photo_path' => 'arbitrary', 'user_id' => $admin->id]))->assertSessionHasErrors(['employee_number', 'first_name', 'employee_type', 'email', 'gender', 'joining_date', 'school_id', 'status', 'photo_path', 'user_id']);
        $this->post('/employees', $this->payload(['date_of_birth' => '2020-04-01']))->assertSessionHasErrors('date_of_birth');
        $this->post('/employees', $this->payload(['date_of_birth' => now()->addDay()->toDateString(), 'joining_date' => now()->addYear()->toDateString()]))->assertSessionHasErrors('date_of_birth');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_employee_number_is_unique_per_school(): void
    {
        $admin = $this->admin();
        $existing = $this->employee($admin, ['employee_number' => 'EMP/001']);
        $other = User::factory()->create();
        $this->employee($other, ['employee_number' => 'EMP/001']);
        $this->actingAs($admin)->post('/employees', $this->payload())->assertSessionHasErrors('employee_number');
        $this->put('/employees/'.$existing->id, $this->payload())->assertRedirect();
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_database_prevents_duplicate_employee_numbers_in_one_school(): void
    {
        $admin = $this->admin();
        $this->employee($admin, ['employee_number' => 'EMP/001']);
        $this->expectException(QueryException::class);
        DB::table('employees')->insert(['school_id' => $admin->school_id, 'employee_number' => 'EMP/001', 'first_name' => 'Duplicate', 'joining_date' => '2020-04-01']);
    }

    public function test_activation_and_deactivation_preserve_records_and_are_audited(): void
    {
        $admin = $this->admin();
        $employee = $this->employee($admin);
        $this->actingAs($admin)->post('/employees/'.$employee->id.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'INACTIVE']);
        $this->get('/employees/'.$employee->id)->assertOk()->assertSee('INACTIVE');
        $this->post('/employees/'.$employee->id.'/activate')->assertRedirect();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMPLOYEE_DEACTIVATED', 'entity_id' => $employee->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMPLOYEE_ACTIVATED', 'entity_id' => $employee->id]);
        $this->delete('/employees/'.$employee->id)->assertStatus(405);
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_view_only_employee_role_cannot_mutate(): void
    {
        $admin = $this->admin();
        $employee = $this->employee($admin);
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'employee.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $this->actingAs($reader)->get('/employees')->assertOk()->assertDontSee('Add employee');
        $this->get('/employees/'.$employee->id)->assertOk()->assertDontSee('Edit employee')->assertDontSee('Confirm deactivation');
        $this->get('/employees/create')->assertForbidden();
        $this->get('/employees/'.$employee->id.'/edit')->assertForbidden();
        $this->post('/employees', $this->payload())->assertForbidden();
        $this->put('/employees/'.$employee->id, $this->payload())->assertForbidden();
        $this->post('/employees/'.$employee->id.'/activate')->assertForbidden();
        $this->post('/employees/'.$employee->id.'/deactivate')->assertForbidden();
    }

    public function test_foreign_employee_ids_are_not_resolved_for_any_endpoint(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $foreign = $this->employee($other, ['first_name' => 'FOREIGN_SECRET']);
        $url = '/employees/'.$foreign->id;
        $this->actingAs($admin)->get('/employees')->assertOk()->assertDontSee('FOREIGN_SECRET');
        foreach (['', '/edit', '/photo'] as $suffix) {
            $this->get($url.$suffix)->assertNotFound();
        }
        $this->put($url, $this->payload())->assertNotFound();
        $this->post($url.'/activate')->assertNotFound();
        $this->post($url.'/deactivate')->assertNotFound();
    }

    public function test_service_also_rejects_cross_school_changes(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $foreign = $this->employee($other);
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(EmployeeService::class)->save($admin, $foreign, $this->payload());
    }

    public function test_search_and_type_status_filters_are_school_scoped(): void
    {
        $admin = $this->admin();
        $one = $this->employee($admin, ['employee_number' => 'A1', 'first_name' => 'Alex', 'last_name' => 'Sharma', 'employee_type' => 'ACCOUNTANT', 'department' => 'Finance']);
        $two = $this->employee($admin, ['employee_number' => 'B2', 'first_name' => 'Different', 'last_name' => 'Person', 'employee_type' => 'TEACHER', 'status' => 'INACTIVE']);
        $this->actingAs($admin)->get('/employees?q=Alex%20Sharma&status=ACTIVE&type=ACCOUNTANT')->assertOk()->assertViewHas('employees', fn ($rows) => $rows->pluck('id')->all() === [$one->id]);
        $this->get('/employees?q=Finance')->assertOk()->assertViewHas('employees', fn ($rows) => $rows->pluck('id')->all() === [$one->id]);
        $this->get('/employees?status=INACTIVE&type=TEACHER')->assertOk()->assertViewHas('employees', fn ($rows) => $rows->pluck('id')->all() === [$two->id]);
        $this->get('/employees?q=0')->assertOk()->assertViewHas('employees', fn ($rows) => $rows->total() === 0);
        $this->get('/employees?status=INVALID')->assertSessionHasErrors('status');
    }

    public function test_listing_is_paginated_and_preserves_filters(): void
    {
        $admin = $this->admin();
        Employee::factory()->count(23)->create(['department' => 'Finance']);
        $this->actingAs($admin)->get('/employees?q=Finance')->assertOk()->assertViewHas('employees', fn ($rows) => $rows->count() === 20 && $rows->total() === 23 && str_contains($rows->url(2), 'q=Finance'));
    }

    public function test_photos_are_private_and_require_employee_view_permission(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/employees', $this->payload(['photo' => UploadedFile::fake()->image('person.jpg', 200, 200)]))->assertRedirect();
        $record = DB::table('employees')->first();
        Storage::disk('local')->assertExists($record->photo_path);
        $this->get('/employees/'.$record->id.'/photo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $sameSchool = User::factory()->create(['school_id' => $admin->school_id]);
        $this->actingAs($sameSchool)->get('/employees/'.$record->id.'/photo')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/employees/'.$record->id.'/photo')->assertNotFound();
        $this->post('/logout');
        $this->get('/employees/'.$record->id.'/photo')->assertRedirect('/login');
    }

    public function test_invalid_photo_format_size_and_dimensions_are_rejected(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin);
        foreach ([UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->image('large.png')->size(2049), UploadedFile::fake()->image('wide.png', 2001, 20), UploadedFile::fake()->create('fake.png', 2, 'text/plain')] as $photo) {
            $this->post('/employees', $this->payload(['photo' => $photo]))->assertSessionHasErrors('photo');
        }
        $this->assertDatabaseCount('employees', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_photo_replacement_and_empty_upload_preserve_expected_files(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/employees', $this->payload(['photo' => UploadedFile::fake()->image('first.png')]))->assertRedirect();
        $record = DB::table('employees')->first();
        $old = $record->photo_path;
        $this->put('/employees/'.$record->id, $this->payload(['photo' => UploadedFile::fake()->image('second.jpg')]))->assertRedirect();
        $new = DB::table('employees')->where('id', $record->id)->value('photo_path');
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertExists($old);
        Storage::disk('local')->assertExists($new);
        $this->put('/employees/'.$record->id, $this->payload())->assertRedirect();
        $this->assertSame($new, DB::table('employees')->where('id', $record->id)->value('photo_path'));
    }

    public function test_failed_audit_rolls_back_employee_creation_and_removes_new_photo(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(EmployeeService::class)->save($admin, null, $this->payload(), UploadedFile::fake()->image('person.png'));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('employees', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

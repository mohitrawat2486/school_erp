<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Services\TeacherProfileService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeacherPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $code = 'DEMO'): User
    {
        Storage::fake('local');
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run($code, 'Demo School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function teacherUser(User $admin, array $permissions): User
    {
        app(CurrentSchool::class)->set($admin->school);
        $user = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, DB::table('permissions')->whereIn('name', $permissions)->pluck('id')->all());
        app(AccessManager::class)->assignRoles($admin, $user, [$role->id]);

        return $user;
    }

    public function test_creation_uploads_a_private_photo_shared_with_the_employee_and_displays_it(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();
        $this->actingAs($admin)->get('/teachers/create?employee_id='.$employee->id)->assertOk()->assertSee('multipart/form-data', false)->assertSee('Teacher photo');
        $this->post('/teachers', ['employee_id' => $employee->id, 'photo' => UploadedFile::fake()->image('teacher.jpg', 200, 200)])->assertRedirect();
        app(CurrentSchool::class)->set($admin->school);
        $profile = TeacherProfile::with('employee')->firstOrFail();
        $path = $employee->fresh()->photo_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('schools/'.$admin->school_id.'/employees/photos/', $path);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMPLOYEE_PHOTO_UPDATED', 'entity_type' => 'employee', 'entity_id' => $employee->id, 'module' => 'teachers']);
        foreach (['/teachers', '/teachers/'.$profile->id, '/teachers/'.$profile->id.'/edit'] as $url) {
            $this->get($url)->assertOk()->assertSee(route('teachers.photo', $profile), false)->assertSee('Photo of '.$employee->fullName());
        }
        $this->get('/teachers/'.$profile->id.'/photo')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/employees/'.$employee->id.'/photo')->assertOk();
    }

    public function test_existing_employee_photo_is_reused_and_empty_upload_preserves_it(): void
    {
        $admin = $this->admin();
        $path = UploadedFile::fake()->image('existing.png')->store('schools/'.$admin->school_id.'/employees/photos', 'local');
        $employee = Employee::factory()->create(['photo_path' => $path]);
        $this->actingAs($admin)->post('/teachers', ['employee_id' => $employee->id])->assertRedirect();
        app(CurrentSchool::class)->set($admin->school);
        $profile = TeacherProfile::with('employee')->firstOrFail();
        $this->put('/teachers/'.$profile->id, ['specialization' => 'Mathematics'])->assertRedirect();
        $this->assertSame($path, $employee->fresh()->photo_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get('/teachers/'.$profile->id.'/photo')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_photo_only_replacement_is_audited_and_preserves_other_employee_details(): void
    {
        $admin = $this->admin();
        $profile = TeacherProfile::factory()->create()->load('employee');
        $employee = $profile->employee;
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('schools/'.$admin->school_id.'/employees/photos', 'local');
        $employee->forceFill(['photo_path' => $oldPath])->save();
        $identity = $employee->only(['first_name', 'last_name', 'employee_number', 'email', 'employee_type']);
        $this->actingAs($admin)->post('/teachers/'.$profile->id, ['_method' => 'PUT', 'photo' => UploadedFile::fake()->image('new.webp')])->assertRedirect('/teachers/'.$profile->id);
        $newPath = $employee->fresh()->photo_path;
        $this->assertNotSame($oldPath, $newPath);
        $this->assertSame($identity, $employee->fresh()->only(array_keys($identity)));
        Storage::disk('local')->assertExists($oldPath);
        Storage::disk('local')->assertExists($newPath);
        $audit = DB::table('audit_logs')->where('action', 'EMPLOYEE_PHOTO_UPDATED')->first();
        $this->assertSame($oldPath, json_decode($audit->old_values, true)['photo_path']);
        $this->assertSame($newPath, json_decode($audit->new_values, true)['photo_path']);
    }

    public function test_teacher_manager_can_upload_and_view_photos_without_employee_permissions(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();
        $manager = $this->teacherUser($admin, ['teacher.view', 'teacher.manage']);
        $this->actingAs($manager)->post('/teachers', ['employee_id' => $employee->id, 'photo' => UploadedFile::fake()->image('first.png')])->assertRedirect();
        app(CurrentSchool::class)->set($admin->school);
        $profile = TeacherProfile::with('employee')->firstOrFail();
        $this->put('/teachers/'.$profile->id, ['photo' => UploadedFile::fake()->image('second.png')])->assertRedirect();
        $this->get('/teachers/'.$profile->id.'/photo')->assertOk();
        $this->get('/employees/'.$employee->id)->assertForbidden();
        $this->get('/employees/'.$employee->id.'/photo')->assertForbidden();
    }

    public function test_photo_read_and_write_obey_teacher_permissions_tenant_scope_and_authentication(): void
    {
        $admin = $this->admin();
        $profile = TeacherProfile::factory()->create()->load('employee');
        $this->actingAs($admin)->put('/teachers/'.$profile->id, ['photo' => UploadedFile::fake()->image('first.png')])->assertRedirect();
        $path = $profile->employee->fresh()->photo_path;
        $reader = $this->teacherUser($admin, ['teacher.view']);
        $this->actingAs($reader)->get('/teachers/'.$profile->id.'/photo')->assertOk();
        $this->put('/teachers/'.$profile->id, ['photo' => UploadedFile::fake()->image('denied.png')])->assertForbidden();
        $this->assertSame($path, $profile->employee->fresh()->photo_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->actingAs(User::factory()->create(['school_id' => $admin->school_id]))->get('/teachers/'.$profile->id.'/photo')->assertForbidden();
        $foreignAdmin = $this->admin('OTHER');
        $this->actingAs($foreignAdmin)->get('/teachers/'.$profile->id.'/photo')->assertNotFound();
        $this->put('/teachers/'.$profile->id, ['photo' => UploadedFile::fake()->image('foreign.png')])->assertNotFound();
        $this->post('/logout');
        $this->get('/teachers/'.$profile->id.'/photo')->assertRedirect('/login');
    }

    public function test_invalid_images_and_direct_storage_paths_are_rejected_without_writes(): void
    {
        $admin = $this->admin();
        $profile = TeacherProfile::factory()->create()->load('employee');
        $this->actingAs($admin);
        foreach ([UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->image('large.png')->size(2049), UploadedFile::fake()->image('wide.png', 2001, 20), UploadedFile::fake()->create('fake.png', 2, 'text/plain'), UploadedFile::fake()->image('animation.gif')] as $photo) {
            $this->put('/teachers/'.$profile->id, ['photo' => $photo])->assertSessionHasErrors('photo');
        }
        $this->put('/teachers/'.$profile->id, ['photo_path' => 'schools/other/private.png'])->assertSessionHasErrors('photo_path');
        $this->assertNull($profile->employee->fresh()->photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->get('/teachers/'.$profile->id.'/photo')->assertNotFound();
    }

    public function test_failed_profile_audit_rolls_back_both_records_and_removes_new_photo(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();
        $real = new AuditLogger;
        $auditCount = DB::table('audit_logs')->count();
        $this->mock(AuditLogger::class)->shouldReceive('record')->twice()->andReturnUsing(function (...$args) use ($real) {
            if ($args[1] === 'TEACHER_PROFILE_CREATED') {
                throw new \RuntimeException('Audit failed');
            }

            return $real->record(...$args);
        });
        try {
            app(TeacherProfileService::class)->save($admin, null, ['employee_id' => $employee->id], UploadedFile::fake()->image('new.png'));
            $this->fail('Expected audit rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertNull($employee->fresh()->photo_path);
        $this->assertDatabaseCount('teacher_profiles', 0);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

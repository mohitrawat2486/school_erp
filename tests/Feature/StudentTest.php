<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Services\StudentService;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentTest extends TestCase
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
        return array_merge(['admission_number' => 'EMP/001', 'first_name' => 'Alex', 'last_name' => 'Sharma', 'admission_date' => '2020-04-01'], $extra);
    }

    private function student(User $owner, array $extra = []): Student
    {
        app(CurrentSchool::class)->set($owner->school);

        return Student::factory()->create($extra);
    }

    public function test_guests_and_accounts_without_permissions_cannot_access_students(): void
    {
        $this->get('/students')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/students')->assertForbidden();
        $this->post('/students', $this->payload())->assertForbidden();
    }

    public function test_admin_can_create_read_and_update_student_with_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/students')->assertOk();
        $this->get('/students/create')->assertOk()->assertSee('Add Student');
        $this->post('/students', $this->payload(['blood_group' => 'B+', 'date_of_birth' => '1988-05-01']))->assertRedirect();
        $id = DB::table('students')->value('id');
        $this->get('/students/'.$id)->assertOk()->assertSee('Alex Sharma')->assertSee('B+');
        $this->get('/students/'.$id.'/edit')->assertOk();
        $this->put('/students/'.$id, $this->payload(['first_name' => 'Updated', 'address' => 'Finance Road']))->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $id, 'school_id' => $admin->school_id, 'first_name' => 'Updated', 'address' => 'Finance Road', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_CREATED', 'entity_id' => $id, 'module' => 'students']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_UPDATED', 'entity_id' => $id]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_fields_and_direct_status_changes_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/students', $this->payload(['admission_number' => '', 'first_name' => '', 'blood_group' => 'INVALID', 'class_id' => 12, 'section_id' => 9, 'roll_number' => '17', 'gender' => 'INVALID', 'admission_date' => 'bad', 'school_id' => 999, 'status' => 'INACTIVE', 'photo_path' => 'arbitrary', 'user_id' => $admin->id]))->assertSessionHasErrors(['admission_number', 'first_name', 'blood_group', 'class_id', 'section_id', 'roll_number', 'gender', 'admission_date', 'school_id', 'status', 'photo_path', 'user_id']);
        $this->post('/students', $this->payload(['date_of_birth' => '2020-04-01']))->assertSessionHasErrors('date_of_birth');
        $this->post('/students', $this->payload(['date_of_birth' => now()->addDay()->toDateString(), 'admission_date' => now()->addYear()->toDateString()]))->assertSessionHasErrors('date_of_birth');
        $this->assertDatabaseCount('students', 0);
    }

    public function test_admission_number_is_unique_per_school(): void
    {
        $admin = $this->admin();
        $existing = $this->student($admin, ['admission_number' => 'EMP/001']);
        $other = User::factory()->create();
        $this->student($other, ['admission_number' => 'EMP/001']);
        $this->actingAs($admin)->post('/students', $this->payload())->assertSessionHasErrors('admission_number');
        $this->put('/students/'.$existing->id, $this->payload())->assertRedirect();
        $this->assertDatabaseCount('students', 2);
    }

    public function test_database_prevents_duplicate_admission_numbers_in_one_school(): void
    {
        $admin = $this->admin();
        $this->student($admin, ['admission_number' => 'EMP/001']);
        $this->expectException(QueryException::class);
        DB::table('students')->insert(['school_id' => $admin->school_id, 'admission_number' => 'EMP/001', 'first_name' => 'Duplicate', 'admission_date' => '2020-04-01']);
    }

    public function test_activation_and_deactivation_preserve_records_and_are_audited(): void
    {
        $admin = $this->admin();
        $student = $this->student($admin);
        $this->actingAs($admin)->post('/students/'.$student->id.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'status' => 'INACTIVE']);
        $this->get('/students/'.$student->id)->assertOk()->assertSee('INACTIVE');
        $this->post('/students/'.$student->id.'/activate')->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_DEACTIVATED', 'entity_id' => $student->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_ACTIVATED', 'entity_id' => $student->id]);
        $this->delete('/students/'.$student->id)->assertStatus(405);
        $this->assertDatabaseCount('students', 1);
    }

    public function test_view_only_student_role_cannot_mutate(): void
    {
        $admin = $this->admin();
        $student = $this->student($admin);
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'student.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $this->actingAs($reader)->get('/students')->assertOk()->assertDontSee('Add student');
        $this->get('/students/'.$student->id)->assertOk()->assertDontSee('Edit student')->assertDontSee('Confirm deactivation');
        $this->get('/students/create')->assertForbidden();
        $this->get('/students/'.$student->id.'/edit')->assertForbidden();
        $this->post('/students', $this->payload())->assertForbidden();
        $this->put('/students/'.$student->id, $this->payload())->assertForbidden();
        $this->post('/students/'.$student->id.'/activate')->assertForbidden();
        $this->post('/students/'.$student->id.'/deactivate')->assertForbidden();
    }

    public function test_foreign_student_ids_are_not_resolved_for_any_endpoint(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $foreign = $this->student($other, ['first_name' => 'FOREIGN_SECRET']);
        $url = '/students/'.$foreign->id;
        $this->actingAs($admin)->get('/students')->assertOk()->assertDontSee('FOREIGN_SECRET');
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
        $foreign = $this->student($other);
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(StudentService::class)->save($admin, $foreign, $this->payload());
    }

    public function test_search_status_and_pagination_are_school_scoped(): void
    {
        $admin = $this->admin();
        $one = $this->student($admin, ['admission_number' => 'ADM-ONE', 'first_name' => 'Alex', 'last_name' => 'Sharma']);
        $two = $this->student($admin, ['admission_number' => 'ADM-TWO', 'first_name' => 'Different', 'status' => 'INACTIVE']);
        $this->actingAs($admin)->get('/students?q=Alex%20Sharma&status=ACTIVE')->assertOk()->assertSee('ADM-ONE')->assertDontSee('ADM-TWO');
        $this->get('/students?status=INACTIVE')->assertOk()->assertSee('ADM-TWO')->assertDontSee('ADM-ONE');
        $this->get('/students?q=0')->assertOk()->assertViewHas('students', fn ($rows) => $rows->total() === 0);
        $this->get('/students?status=INVALID')->assertSessionHasErrors('status');
        app(CurrentSchool::class)->set($admin->school);
        Student::factory()->count(23)->create(['first_name' => 'Searchable']);
        $this->get('/students?q=Searchable')->assertOk()->assertViewHas('students', fn ($rows) => $rows->count() === 20 && $rows->total() === 23 && str_contains($rows->url(2), 'q=Searchable'));
    }

    public function test_photos_are_private_and_require_student_view_permission(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/students', $this->payload(['photo' => UploadedFile::fake()->image('person.jpg', 200, 200)]))->assertRedirect();
        $record = DB::table('students')->first();
        Storage::disk('local')->assertExists($record->photo_path);
        $this->get('/students/'.$record->id.'/photo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $sameSchool = User::factory()->create(['school_id' => $admin->school_id]);
        $this->actingAs($sameSchool)->get('/students/'.$record->id.'/photo')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/students/'.$record->id.'/photo')->assertNotFound();
        $this->post('/logout');
        $this->get('/students/'.$record->id.'/photo')->assertRedirect('/login');
    }

    public function test_invalid_photo_format_size_and_dimensions_are_rejected(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin);
        foreach ([UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->image('large.png')->size(2049), UploadedFile::fake()->image('wide.png', 2001, 20), UploadedFile::fake()->create('fake.png', 2, 'text/plain')] as $photo) {
            $this->post('/students', $this->payload(['photo' => $photo]))->assertSessionHasErrors('photo');
        }
        $this->assertDatabaseCount('students', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_photo_replacement_and_empty_upload_preserve_expected_files(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/students', $this->payload(['photo' => UploadedFile::fake()->image('first.png')]))->assertRedirect();
        $record = DB::table('students')->first();
        $old = $record->photo_path;
        $this->put('/students/'.$record->id, $this->payload(['photo' => UploadedFile::fake()->image('second.jpg')]))->assertRedirect();
        $new = DB::table('students')->where('id', $record->id)->value('photo_path');
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertExists($old);
        Storage::disk('local')->assertExists($new);
        $this->put('/students/'.$record->id, $this->payload())->assertRedirect();
        $this->assertSame($new, DB::table('students')->where('id', $record->id)->value('photo_path'));
    }

    public function test_failed_audit_rolls_back_student_creation_and_removes_new_photo(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(StudentService::class)->save($admin, null, $this->payload(), UploadedFile::fake()->image('person.png'));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('students', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

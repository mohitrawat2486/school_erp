<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Services\StudentService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentAdmissionGuardianTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('ADMISSION', 'Admission School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function data(array $extra = []): array
    {
        return array_replace(['aadhaar_number' => '234567890123', 'first_name' => 'Aarav', 'last_name' => 'Sharma', 'admission_number' => 'ADM-001', 'admission_date' => '2026-04-01', 'address' => '12 School Road', 'guardian_mode' => 'new', 'guardian' => ['first_name' => 'Anita', 'last_name' => 'Sharma', 'phone' => '+91 9876543210', 'same_address' => true, 'email' => 'anita@example.test'], 'guardian_relationship' => 'MOTHER', 'guardian_emergency' => true], $extra);
    }

    private function user(User $admin, array $permissions): User
    {
        app(CurrentSchool::class)->set($admin->school);
        $user = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::create(['name' => 'ADMISSION-'.fake()->unique()->numerify('######')]);
        app(AccessManager::class)->setPermissions($admin, $role, DB::table('permissions')->whereIn('name', $permissions)->pluck('id')->all());
        app(AccessManager::class)->assignRoles($admin, $user, [$role->id]);

        return $user;
    }

    public function test_new_guardian_and_primary_contact_are_saved_with_student_and_photo(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin)->get('/students/create')->assertOk()->assertSee('Guardians')->assertSee('Existing contact')->assertSee('Add guardian');
        $this->post('/students', $this->data(['photo' => UploadedFile::fake()->image('student.png')]))->assertRedirect();
        $student = DB::table('students')->first();
        $guardian = DB::table('guardians')->first();
        $this->assertSame('12 School Road', $guardian->address);
        $this->assertDatabaseHas('student_guardians', ['student_id' => $student->id, 'guardian_id' => $guardian->id, 'school_id' => $admin->school_id, 'relationship' => 'MOTHER', 'is_primary' => true, 'is_emergency_contact' => true, 'status' => 'ACTIVE']);
        foreach (['STUDENT_CREATED', 'GUARDIAN_CREATED', 'STUDENT_GUARDIAN_LINKED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
        Storage::disk('local')->assertExists($student->photo_path);
        $this->get('/students/'.$student->id)->assertOk()->assertSee('Anita Sharma')->assertSee('Primary contact')->assertSee('Emergency contact');
        $this->get('/students/'.$student->id.'/edit')->assertOk()->assertSee('data-guardian-editor', false)->assertDontSee('Manage guardian contacts')->assertDontSee('name="guardian_mode"', false);
    }

    public function test_existing_guardian_is_shared_with_siblings_without_overwriting_details(): void
    {
        $admin = $this->admin();
        $guardian = Guardian::factory()->create(['phone' => 'ORIGINAL-PHONE']);
        $before = $guardian->fresh()->getAttributes();
        $this->actingAs($admin);
        for ($i = 1; $i <= 2; $i++) {
            $this->post('/students', $this->data(['admission_number' => 'ADM-'.$i, 'guardian_mode' => 'existing', 'guardian_id' => $guardian->id, 'guardian_emergency' => false]))->assertRedirect();
        }
        app(CurrentSchool::class)->set($admin->school);
        $this->assertSame($before, $guardian->fresh()->getAttributes());
        $this->assertDatabaseCount('guardians', 1);
        $this->assertDatabaseCount('student_guardians', 2);
        $this->assertSame(2, DB::table('student_guardians')->where('is_primary', true)->where('is_emergency_contact', false)->count());
    }

    public function test_guardian_can_be_added_later_and_legacy_student_only_submission_still_works(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/students', $this->data(['guardian_mode' => 'none', 'guardian' => ['email' => 'invalid']]))->assertRedirect();
        $data = $this->data(['admission_number' => 'ADM-002']);
        foreach (['guardian_mode', 'guardian', 'guardian_relationship', 'guardian_emergency'] as $key) {
            unset($data[$key]);
        }
        $this->post('/students', $data)->assertRedirect();
        $this->assertDatabaseCount('students', 2);
        $this->assertDatabaseCount('guardians', 0);
        $this->assertDatabaseCount('student_guardians', 0);
    }

    public function test_guardian_validation_preserves_input_and_does_not_create_partial_records(): void
    {
        $admin = $this->admin();
        $data = $this->data(['guardian' => ['first_name' => 'Anita', 'phone' => '', 'same_address' => false, 'email' => 'bad-email']]);
        $this->actingAs($admin)->from('/students/create')->post('/students', $data)->assertRedirect('/students/create')->assertSessionHasErrors(['guardian.phone', 'guardian.email'])->assertSessionHasInput('first_name', 'Aarav')->assertSessionHasInput('guardian.first_name', 'Anita');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/students/create')->assertOk()->assertSee('Anita')->assertSee('bad-email')->assertSee('The guardian phone field is required.');
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('guardians', 0);
        foreach (['guardian_relationship' => 'OTHER', 'guardian_emergency' => 'yes', 'guardian_mode' => 'arbitrary'] as $field => $value) {
            $this->post('/students', $this->data([$field => $value]))->assertSessionHasErrors($field);
        }
        $data = $this->data();
        $data['guardian']['school_id'] = 999;
        $this->post('/students', $data)->assertSessionHasErrors('guardian');
        $this->assertDatabaseCount('students', 0);
    }

    public function test_own_guardian_address_is_saved_when_same_address_is_unchecked(): void
    {
        $admin = $this->admin();
        $data = $this->data();
        $data['guardian']['same_address'] = false;
        $data['guardian']['address'] = '45 Another Road';
        $this->actingAs($admin)->post('/students', $data)->assertRedirect();
        $this->assertDatabaseHas('guardians', ['address' => '45 Another Road']);
    }

    public function test_inactive_and_foreign_guardians_and_malformed_selections_are_rejected(): void
    {
        $admin = $this->admin();
        $inactive = Guardian::factory()->create(['status' => 'INACTIVE']);
        $foreignUser = User::factory()->create();
        app(CurrentSchool::class)->set($foreignUser->school);
        $foreign = Guardian::factory()->create();
        app(CurrentSchool::class)->set($admin->school);
        $this->actingAs($admin);
        foreach ([$inactive->id, $foreign->id, 9999999, ['bad']] as $id) {
            $this->from('/students/create')->post('/students', $this->data(['guardian_mode' => 'existing', 'guardian_id' => $id]))->assertSessionHasErrors('guardian_id');
        }
        $this->get('/students/create')->assertOk();
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('student_guardians', 0);
    }

    public function test_guardian_permissions_are_required_without_expanding_student_creation_permissions(): void
    {
        $admin = $this->admin();
        $creator = $this->user($admin, ['student.view', 'student.create']);
        $guardian = Guardian::factory()->create();
        $this->actingAs($creator)->get('/students/create')->assertOk()->assertDontSee('name="guardian_mode"', false);
        $this->post('/students', $this->data())->assertForbidden();
        $this->post('/students', $this->data(['guardian_mode' => 'existing', 'guardian_id' => $guardian->id]))->assertForbidden();
        $this->get('/students/guardian-options')->assertForbidden();
        $this->post('/students', $this->data(['guardian_mode' => 'none']))->assertRedirect();
        $updater = $this->user($admin, ['student.view', 'student.update']);
        $this->actingAs($updater)->get('/students/guardian-options')->assertForbidden();
        $this->post('/students', $this->data(['admission_number' => 'DENIED']))->assertForbidden();
        $this->assertDatabaseCount('students', 1);
    }

    public function test_search_is_bounded_active_school_scoped_and_returns_only_picker_fields(): void
    {
        $admin = $this->admin();
        Guardian::factory()->count(23)->create(['first_name' => 'Contact']);
        Guardian::factory()->create(['first_name' => 'PRIVATE-INACTIVE', 'status' => 'INACTIVE']);
        $named = Guardian::factory()->create(['first_name' => 'Anita', 'last_name' => 'Sharma', 'phone' => '9876543210', 'address' => 'PRIVATE ADDRESS']);
        $foreignUser = User::factory()->create();
        app(CurrentSchool::class)->set($foreignUser->school);
        Guardian::factory()->create(['first_name' => 'PRIVATE-FOREIGN']);
        app(CurrentSchool::class)->set($admin->school);
        $this->actingAs($admin)->getJson('/students/guardian-options')->assertOk()->assertJsonCount(20, 'guardians')->assertJsonPath('has_more', true)->assertDontSee('PRIVATE-INACTIVE')->assertDontSee('PRIVATE-FOREIGN');
        foreach (['Anita Sharma', '987654'] as $term) {
            $this->getJson('/students/guardian-options?'.http_build_query(['q' => $term]))->assertOk()->assertExactJson(['guardians' => [['id' => $named->id, 'name' => 'Anita Sharma', 'phone' => '9876543210']], 'has_more' => false]);
        }
        $this->getJson('/students/guardian-options?q=not-found')->assertOk()->assertJsonCount(0, 'guardians');
        $this->getJson('/students/guardian-options?'.http_build_query(['q' => ['invalid']]))->assertUnprocessable();
        $this->getJson('/students/guardian-options?q='.str_repeat('a', 101))->assertUnprocessable();
        $this->post('/logout');
        $this->get('/students/guardian-options')->assertRedirect('/login');
    }

    public function test_failed_link_audit_rolls_back_student_guardian_photo_and_all_audits(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $real = new AuditLogger;
        $before = DB::table('audit_logs')->count();
        $this->mock(AuditLogger::class)->shouldReceive('record')->times(3)->andReturnUsing(function (...$args) use ($real) {
            if ($args[1] === 'STUDENT_GUARDIAN_LINKED') {
                throw new \RuntimeException('Contact audit failed');
            }

            return $real->record(...$args);
        });
        try {
            app(StudentService::class)->save($admin, null, $this->data(), UploadedFile::fake()->image('student.png'));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Contact audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('guardians', 0);
        $this->assertDatabaseCount('student_guardians', 0);
        $this->assertDatabaseCount('audit_logs', $before);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_editing_a_student_cannot_silently_create_or_replace_guardian_contacts(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $this->actingAs($admin)->put('/students/'.$student->id, $this->data())->assertSessionHasErrors(['guardian_mode', 'guardian', 'guardian_relationship', 'guardian_emergency']);
        $this->assertDatabaseCount('guardians', 0);
        $this->assertDatabaseCount('student_guardians', 0);
    }
}

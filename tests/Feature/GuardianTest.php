<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\GuardianService;
use App\Services\ProvisionSchool;
use App\Services\StudentGuardianService;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuardianTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('GUARDIAN', 'Guardian School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function payload(array $data = []): array
    {
        return array_merge(['first_name' => 'Anita', 'last_name' => 'Sharma', 'phone' => '+91 9876543210', 'email' => 'anita@example.test'], $data);
    }

    private function contact(array $data = []): array
    {
        return array_merge(['relationship' => 'MOTHER', 'is_primary' => true, 'is_emergency_contact' => true], $data);
    }

    private function linkUrl(Student $student, Guardian $guardian): string
    {
        return '/students/'.$student->id.'/guardians/'.$guardian->id;
    }

    public function test_crud_validation_status_and_audits(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/guardians')->assertOk();
        $this->get('/guardians/create')->assertOk();
        $this->post('/guardians', $this->payload())->assertRedirect();
        $id = DB::table('guardians')->value('id');
        $this->get('/guardians/'.$id)->assertOk()->assertSee('Anita Sharma');
        $this->get('/guardians/'.$id.'/edit')->assertOk();
        $this->put('/guardians/'.$id, $this->payload(['occupation' => 'Doctor']))->assertRedirect();
        $this->post('/guardians/'.$id.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('guardians', ['id' => $id, 'status' => 'INACTIVE', 'occupation' => 'Doctor']);
        $this->post('/guardians/'.$id.'/activate')->assertRedirect();
        foreach (['GUARDIAN_CREATED', 'GUARDIAN_UPDATED', 'GUARDIAN_DEACTIVATED', 'GUARDIAN_ACTIVATED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $id]);
        }
        $this->post('/guardians', $this->payload(['first_name' => '', 'phone' => '', 'email' => 'invalid', 'school_id' => 999, 'status' => 'ACTIVE', 'student_id' => 55]))->assertSessionHasErrors(['first_name', 'phone', 'email', 'school_id', 'status', 'student_id']);
        $this->delete('/guardians/'.$id)->assertStatus(405);
        $this->assertDatabaseCount('guardians', 1);
    }

    public function test_multiple_guardians_and_shared_sibling_contact_keep_independent_relationships(): void
    {
        $admin = $this->admin();
        $students = Student::factory()->count(2)->create();
        $guardians = Guardian::factory()->count(2)->create();
        $this->actingAs($admin)->put($this->linkUrl($students[0], $guardians[0]), $this->contact())->assertRedirect();
        $this->put($this->linkUrl($students[1], $guardians[0]), $this->contact(['relationship' => 'GUARDIAN', 'is_emergency_contact' => false]))->assertRedirect();
        $this->put($this->linkUrl($students[0], $guardians[1]), $this->contact(['relationship' => 'FATHER', 'is_primary' => false]))->assertRedirect();
        $this->assertDatabaseCount('student_guardians', 3);
        $this->get('/students/'.$students[0]->id)->assertOk()->assertSee($guardians[0]->fullName())->assertSee($guardians[1]->fullName())->assertSee('Primary contact');
        $this->get('/guardians/'.$guardians[0]->id)->assertOk()->assertSee($students[0]->admission_number)->assertSee($students[1]->admission_number);
        $this->get('/students/'.$students[0]->id.'/guardians')->assertOk()->assertSee('Save contact');
        $this->put('/guardians/'.$guardians[0]->id, $this->payload(['phone' => 'UPDATED-PHONE']))->assertRedirect();
        $this->get('/students/'.$students[0]->id)->assertSee('UPDATED-PHONE');
        $this->get('/students/'.$students[1]->id)->assertSee('UPDATED-PHONE');
    }

    public function test_primary_replacement_unlink_and_relink_retain_history_and_are_idempotent(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        [$one,$two] = Guardian::factory()->count(2)->create()->all();
        $this->actingAs($admin)->put($this->linkUrl($student, $one), $this->contact())->assertRedirect();
        $this->put($this->linkUrl($student, $two), $this->contact())->assertRedirect();
        $this->assertDatabaseHas('student_guardians', ['student_id' => $student->id, 'guardian_id' => $one->id, 'is_primary' => false]);
        $this->assertDatabaseHas('student_guardians', ['student_id' => $student->id, 'guardian_id' => $two->id, 'is_primary' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_GUARDIAN_PRIMARY_REPLACED']);
        $count = DB::table('audit_logs')->count();
        $this->put($this->linkUrl($student, $two), $this->contact())->assertRedirect();
        $this->assertDatabaseCount('audit_logs', $count);
        $this->post($this->linkUrl($student, $two).'/unlink')->assertRedirect();
        $id = DB::table('student_guardians')->where('guardian_id', $two->id)->value('id');
        $this->assertDatabaseHas('student_guardians', ['id' => $id, 'status' => 'INACTIVE', 'is_primary' => false]);
        $this->post($this->linkUrl($student, $two).'/unlink')->assertRedirect();
        $this->put($this->linkUrl($student, $two), $this->contact())->assertRedirect();
        $this->assertDatabaseHas('student_guardians', ['id' => $id, 'status' => 'ACTIVE', 'is_primary' => true]);
        $this->assertDatabaseCount('student_guardians', 2);
    }

    public function test_inactive_guardian_and_primary_deactivation_rules(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $guardian = Guardian::factory()->create();
        $this->actingAs($admin)->put($this->linkUrl($student, $guardian), $this->contact())->assertRedirect();
        $this->post('/guardians/'.$guardian->id.'/deactivate')->assertSessionHasErrors('guardian');
        $this->post($this->linkUrl($student, $guardian).'/unlink')->assertRedirect();
        $this->post('/guardians/'.$guardian->id.'/deactivate')->assertRedirect();
        $this->put($this->linkUrl($student, $guardian), $this->contact())->assertSessionHasErrors('guardian');
        $this->post('/guardians/'.$guardian->id.'/activate')->assertRedirect();
        $this->put($this->linkUrl($student, $guardian), $this->contact())->assertRedirect();
    }

    public function test_invalid_relationship_flags_and_spoofed_ids_are_rejected(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $guardian = Guardian::factory()->create();
        $this->actingAs($admin)->put($this->linkUrl($student, $guardian), $this->contact(['relationship' => 'OTHER', 'is_primary' => 'yes', 'is_emergency_contact' => 2, 'school_id' => 999, 'student_id' => 999, 'guardian_id' => 999, 'status' => 'ACTIVE']))->assertSessionHasErrors(['relationship', 'is_primary', 'is_emergency_contact', 'school_id', 'student_id', 'guardian_id', 'status']);
        $this->assertDatabaseCount('student_guardians', 0);
    }

    public function test_read_only_accounts_and_guests_cannot_mutate_contacts(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $guardian = Guardian::factory()->create();
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'student.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $this->actingAs($reader)->get('/guardians')->assertOk()->assertDontSee('Add guardian');
        $this->get('/guardians/'.$guardian->id)->assertOk()->assertDontSee('Edit guardian');
        $this->get('/students/'.$student->id)->assertOk()->assertDontSee('Manage guardian contacts');
        $this->get('/guardians/create')->assertForbidden();
        $this->post('/guardians', $this->payload())->assertForbidden();
        $this->get('/guardians/'.$guardian->id.'/edit')->assertForbidden();
        $this->put('/guardians/'.$guardian->id, $this->payload())->assertForbidden();
        $this->get('/students/'.$student->id.'/guardians')->assertForbidden();
        $this->put($this->linkUrl($student, $guardian), $this->contact())->assertForbidden();
        $this->post($this->linkUrl($student, $guardian).'/unlink')->assertForbidden();
        $this->post('/guardians/'.$guardian->id.'/deactivate')->assertForbidden();
        $this->post('/logout');
        $this->get('/guardians')->assertRedirect('/login');
        $this->put($this->linkUrl($student, $guardian), $this->contact())->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/guardians')->assertForbidden();
    }

    public function test_foreign_records_are_never_resolved_or_listed(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $guardian = Guardian::factory()->create();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = Guardian::factory()->create(['first_name' => 'FOREIGN-SECRET']);
        $foreignStudent = Student::factory()->create();
        $this->actingAs($admin)->get('/guardians')->assertOk()->assertDontSee('FOREIGN-SECRET');
        foreach (['', '/edit'] as $suffix) {
            $this->get('/guardians/'.$foreign->id.$suffix)->assertNotFound();
        }
        $this->put('/guardians/'.$foreign->id, $this->payload())->assertNotFound();
        $this->post('/guardians/'.$foreign->id.'/activate')->assertNotFound();
        $this->put($this->linkUrl($student, $foreign), $this->contact())->assertNotFound();
        $this->put($this->linkUrl($foreignStudent, $guardian), $this->contact())->assertNotFound();
        $this->post($this->linkUrl($student, $foreign).'/unlink')->assertNotFound();
        $this->get('/students/'.$foreignStudent->id.'/guardians')->assertNotFound();
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(StudentGuardianService::class)->save($admin, $foreignStudent, $guardian, $this->contact());
    }

    public function test_search_pagination_and_status_filters_preserve_query(): void
    {
        $admin = $this->admin();
        $guardian = Guardian::factory()->create(['first_name' => 'Anita', 'last_name' => 'Sharma', 'phone' => 'PHONE-UNIQUE', 'email' => 'unique@example.test']);
        $inactive = Guardian::factory()->create(['status' => 'INACTIVE']);
        $this->actingAs($admin)->get('/guardians?q=Anita%20Sharma')->assertOk()->assertSee('PHONE-UNIQUE')->assertDontSee($inactive->phone);
        $this->get('/guardians?q=unique@example.test')->assertSee('PHONE-UNIQUE');
        $this->get('/guardians?status=INACTIVE')->assertSee($inactive->phone)->assertDontSee('PHONE-UNIQUE');
        $this->get('/guardians?status=OTHER')->assertSessionHasErrors('status');
        app(CurrentSchool::class)->set($admin->school);
        Guardian::factory()->count(23)->create(['first_name' => 'Searchable']);
        $this->get('/guardians?q=Searchable')->assertOk()->assertViewHas('guardians', fn ($rows) => $rows->count() === 20 && $rows->total() === 23 && str_contains($rows->url(2), 'q=Searchable'));
    }

    public function test_audit_failure_rolls_back_primary_replacement(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        [$one,$two] = Guardian::factory()->count(2)->create()->all();
        app(StudentGuardianService::class)->save($admin, $student, $one, $this->contact());
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(StudentGuardianService::class)->save($admin, $student, $two, $this->contact());
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }
        $this->assertDatabaseHas('student_guardians', ['guardian_id' => $one->id, 'is_primary' => true]);
        $this->assertDatabaseCount('student_guardians', 1);
    }

    public function test_database_rejects_cross_school_link(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $guardian = Guardian::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('student_guardians')->insert(['school_id' => $admin->school_id, 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'relationship' => 'FATHER']);
    }

    public function test_database_rejects_second_primary_contact(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        [$one,$two] = Guardian::factory()->count(2)->create()->all();
        app(StudentGuardianService::class)->save($admin, $student, $one, $this->contact());
        $this->expectException(QueryException::class);
        DB::table('student_guardians')->insert(['school_id' => $admin->school_id, 'student_id' => $student->id, 'guardian_id' => $two->id, 'relationship' => 'FATHER', 'is_primary' => true]);
    }

    public function test_database_rejects_invalid_relationship(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $guardian = Guardian::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('student_guardians')->insert(['school_id' => $admin->school_id, 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'relationship' => 'OTHER']);
    }

    public function test_guardian_save_rolls_back_when_audit_fails(): void
    {
        $admin = $this->admin();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(GuardianService::class)->save($admin, null, $this->payload());
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }
        $this->assertDatabaseCount('guardians', 0);
    }

    public function test_deactivation_checks_primary_contacts_across_siblings(): void
    {
        $admin = $this->admin();
        [$one,$two] = Student::factory()->count(2)->create()->all();
        $guardian = Guardian::factory()->create();
        app(StudentGuardianService::class)->save($admin, $one, $guardian, $this->contact());
        app(StudentGuardianService::class)->save($admin, $two, $guardian, $this->contact());
        $this->actingAs($admin)->post($this->linkUrl($one, $guardian).'/unlink')->assertRedirect();
        $this->post('/guardians/'.$guardian->id.'/deactivate')->assertSessionHasErrors('guardian');
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id, 'status' => 'ACTIVE']);
        $this->post($this->linkUrl($two, $guardian).'/unlink')->assertRedirect();
        $this->post('/guardians/'.$guardian->id.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id, 'status' => 'INACTIVE']);
    }
}

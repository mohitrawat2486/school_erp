<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('ENROLL', 'Enrollment School', $u->email);
        $u = $u->fresh();
        app(CurrentSchool::class)->set($u->school);

        return $u;
    }

    private function context(User $u): array
    {
        app(CurrentSchool::class)->set($u->school);
        $student = Student::factory()->create(['admission_date' => '2026-04-01']);
        $year = AcademicYear::create(['name' => fake()->unique()->bothify('Year-########'), 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        $year->status = 'ACTIVE';
        $year->save();
        $section = Section::factory()->create();

        return ['student_id' => $student->id, 'academic_year_id' => $year->id, 'class_id' => $section->class_id, 'section_id' => $section->id, 'roll_number' => 1, 'joined_at' => '2026-04-01'];
    }

    private function create(User $u, array $data): StudentEnrollment
    {
        app(CurrentSchool::class)->set($u->school);

        return app(EnrollmentService::class)->save($u, null, $data);
    }

    public function test_selection_wizard_and_enrollment_crud_audits(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $this->actingAs($admin)->get('/enrollments')->assertOk();
        $selection = [];
        foreach (StudentEnrollment::CONTEXT as $field) {
            $this->get('/enrollments/create?'.http_build_query($selection))->assertOk()->assertSee('Select');
            $selection[$field] = $data[$field];
        }
        $this->get('/enrollments/create?'.http_build_query($selection))->assertOk()->assertSee('Confirm enrollment');
        $this->post('/enrollments', $data)->assertRedirect();
        $id = DB::table('student_enrollments')->value('id');
        $this->get('/enrollments/'.$id)->assertOk()->assertSee('Enrollment details');
        $this->get('/enrollments/'.$id.'/edit')->assertOk();
        $this->put('/enrollments/'.$id, ['roll_number' => 7, 'joined_at' => '2026-04-02'])->assertRedirect();
        $this->get('/students/'.$data['student_id'])->assertOk()->assertSee('Enrollment history')->assertViewHas('enrollments', fn ($rows) => $rows->total() === 1 && $rows->first()->roll_number === 7);
        $this->assertDatabaseHas('student_enrollments', ['id' => $id, 'roll_number' => 7]);
        foreach (['ENROLLMENT_CREATED', 'ENROLLMENT_UPDATED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $id]);
        }$this->delete('/enrollments/'.$id)->assertStatus(405);
    }

    public function test_one_enrollment_per_year_and_distinct_year_history(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $first = $this->create($admin, $data);
        $this->actingAs($admin)->post('/enrollments', $data)->assertSessionHasErrors('student_id');
        app(CurrentSchool::class)->set($admin->school);
        $next = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $next->status = 'ACTIVE';
        $next->save();
        $this->create($admin, [...$data, 'academic_year_id' => $next->id, 'joined_at' => '2027-04-01']);
        $this->assertDatabaseCount('student_enrollments', 2);
        $this->assertDatabaseHas('student_enrollments', ['id' => $first->id, 'academic_year_id' => $data['academic_year_id']]);
    }

    public function test_roll_uniqueness_includes_inactive_history_and_is_section_scoped(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $record = $this->create($admin, $data);
        $other = Student::factory()->create();
        $this->actingAs($admin)->post('/enrollments', [...$data, 'student_id' => $other->id])->assertSessionHasErrors('roll_number');
        $this->post('/enrollments/'.$record->id.'/deactivate')->assertRedirect();
        $this->post('/enrollments', [...$data, 'student_id' => $other->id])->assertSessionHasErrors('roll_number');
        app(CurrentSchool::class)->set($admin->school);
        $section = Section::factory()->create(['class_id' => $data['class_id'], 'name' => 'B']);
        $this->create($admin, [...$data, 'student_id' => $other->id, 'section_id' => $section->id]);
        $this->assertDatabaseCount('student_enrollments', 2);
    }

    public function test_capacity_is_per_year_and_reactivation_rechecks_availability(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        Section::whereKey($data['section_id'])->update(['capacity' => 1]);
        $first = $this->create($admin, $data);
        $other = Student::factory()->create();
        $this->actingAs($admin)->post('/enrollments', [...$data, 'student_id' => $other->id, 'roll_number' => 2])->assertSessionHasErrors('section_id');
        $this->post('/enrollments/'.$first->id.'/deactivate')->assertRedirect();
        $this->post('/enrollments', [...$data, 'student_id' => $other->id, 'roll_number' => 2])->assertRedirect();
        $this->post('/enrollments/'.$first->id.'/activate')->assertSessionHasErrors('section_id');
        $this->assertDatabaseHas('student_enrollments', ['id' => $first->id, 'status' => 'INACTIVE']);
        app(CurrentSchool::class)->set($admin->school);
        $next = AcademicYear::create(['name' => 'Next', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $next->status = 'ACTIVE';
        $next->save();
        $this->create($admin, [...$data, 'academic_year_id' => $next->id, 'joined_at' => '2027-04-01']);
        $this->assertDatabaseCount('student_enrollments', 3);
    }

    public function test_dates_rolls_and_forbidden_placement_changes_are_validated(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $this->actingAs($admin);
        foreach (['2026-03-31', '2027-04-01', 'invalid'] as $date) {
            $this->post('/enrollments', [...$data, 'joined_at' => $date])->assertSessionHasErrors('joined_at');
        }
        foreach ([0, -1, 'A', 4294967296] as $roll) {
            $this->post('/enrollments', [...$data, 'roll_number' => $roll])->assertSessionHasErrors('roll_number');
        }
        $this->post('/enrollments', [...$data, 'school_id' => 999, 'status' => 'INACTIVE'])->assertSessionHasErrors(['school_id', 'status']);
        app(CurrentSchool::class)->set($admin->school);
        Student::whereKey($data['student_id'])->update(['admission_date' => '2026-05-01']);
        $this->post('/enrollments', $data)->assertSessionHasErrors('joined_at');
        $data['joined_at'] = '2026-05-01';
        $record = $this->create($admin, $data);
        $this->put('/enrollments/'.$record->id, $data)->assertSessionHasErrors(StudentEnrollment::CONTEXT);
    }

    public function test_mismatched_class_section_and_inactive_parents_are_rejected(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $wrong = Section::factory()->create();
        $this->actingAs($admin)->post('/enrollments', [...$data, 'section_id' => $wrong->id])->assertSessionHasErrors('section_id');
        foreach (['students' => 'student_id', 'classes' => 'class_id', 'sections' => 'section_id', 'academic_years' => 'academic_year_id'] as $table => $field) {
            DB::table($table)->where('id', $data[$field])->update(['status' => $table === 'academic_years' ? 'DRAFT' : 'INACTIVE']);
            $this->post('/enrollments', $data)->assertSessionHasErrors();
            DB::table($table)->where('id', $data[$field])->update(['status' => 'ACTIVE']);
        }
        $this->assertDatabaseCount('student_enrollments', 0);
    }

    public function test_closed_archived_and_draft_years_are_read_only(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $record = $this->create($admin, $data);
        $this->actingAs($admin);
        foreach (['CLOSED', 'ARCHIVED', 'DRAFT'] as $status) {
            DB::table('academic_years')->where('id', $data['academic_year_id'])->update(['status' => $status]);
            $this->get('/enrollments/'.$record->id)->assertOk()->assertDontSee('Edit enrollment');
            $this->get('/enrollments/'.$record->id.'/edit')->assertForbidden();
            $this->put('/enrollments/'.$record->id, ['roll_number' => 2, 'joined_at' => '2026-04-01'])->assertForbidden();
            $this->post('/enrollments/'.$record->id.'/deactivate')->assertForbidden();
        }
    }

    public function test_status_changes_are_audited_idempotent_and_retain_record(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $record = $this->create($admin, $data);
        $this->actingAs($admin)->post('/enrollments/'.$record->id.'/deactivate')->assertRedirect();
        $count = DB::table('audit_logs')->count();
        $this->post('/enrollments/'.$record->id.'/deactivate')->assertRedirect();
        $this->assertDatabaseCount('audit_logs', $count);
        $this->post('/enrollments/'.$record->id.'/activate')->assertRedirect();
        $this->assertDatabaseHas('student_enrollments', ['id' => $record->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ENROLLMENT_DEACTIVATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ENROLLMENT_ACTIVATED']);
    }

    public function test_read_only_users_and_guests_cannot_mutate(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $record = $this->create($admin, $data);
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'student.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $this->actingAs($reader)->get('/enrollments')->assertOk()->assertDontSee('Enroll student');
        $this->get('/enrollments/'.$record->id)->assertOk()->assertDontSee('Edit enrollment');
        $this->get('/enrollments/create')->assertForbidden();
        $this->post('/enrollments', $data)->assertForbidden();
        $this->put('/enrollments/'.$record->id, ['roll_number' => 2, 'joined_at' => '2026-04-01'])->assertForbidden();
        $this->post('/enrollments/'.$record->id.'/activate')->assertForbidden();
        $this->post('/logout');
        $this->get('/enrollments')->assertRedirect('/login');
        $this->post('/enrollments', $data)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/enrollments')->assertForbidden();
    }

    public function test_foreign_ids_do_not_resolve_and_foreign_payloads_are_rejected(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other', $other->email);
        $other = $other->fresh();
        $foreignData = $this->context($other);
        $foreign = $this->create($other, $foreignData);
        $admission = $foreign->student->admission_number;
        $this->actingAs($admin)->get('/enrollments')->assertOk()->assertDontSee($admission);
        foreach (['', '/edit'] as $suffix) {
            $this->get('/enrollments/'.$foreign->id.$suffix)->assertNotFound();
        }$this->post('/enrollments/'.$foreign->id.'/activate')->assertNotFound();
        $this->put('/enrollments/'.$foreign->id, [])->assertNotFound();
        foreach (StudentEnrollment::CONTEXT as $field) {
            $this->post('/enrollments', [...$data, $field => $foreignData[$field]])->assertSessionHasErrors($field);
        }
        $this->get('/enrollments/create?student_id='.$foreignData['student_id'])->assertNotFound();
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(EnrollmentService::class)->setStatus($admin, $foreign, EnrollmentStatus::Inactive);
    }

    public function test_search_filters_and_pagination_are_scoped(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $record = $this->create($admin, $data);
        $admission = $record->student->admission_number;
        $this->actingAs($admin)->get('/enrollments?q='.urlencode($admission))->assertOk()->assertSee($admission);
        $this->get('/enrollments?status=INACTIVE')->assertOk()->assertDontSee($admission);
        $this->get('/enrollments?status=BAD')->assertSessionHasErrors('status');
        app(CurrentSchool::class)->set($admin->school);
        foreach (range(2, 23) as $roll) {
            $student = Student::factory()->create();
            $this->create($admin, [...$data, 'student_id' => $student->id, 'roll_number' => $roll]);
        }
        $this->get('/enrollments?academic_year_id='.$data['academic_year_id'])->assertOk()->assertViewHas('enrollments', fn ($rows) => $rows->total() === 23 && $rows->count() === 20 && str_contains($rows->url(2), 'academic_year_id='));
    }

    public function test_audit_failure_rolls_back_enrollment(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            $this->create($admin, $data);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }$this->assertDatabaseCount('student_enrollments', 0);
    }

    public function test_database_rejects_duplicate_student_year(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $this->create($admin, $data);
        $this->expectException(QueryException::class);
        DB::table('student_enrollments')->insert([...$data, 'school_id' => $admin->school_id, 'roll_number' => 2]);
    }

    public function test_database_rejects_duplicate_roll(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $this->create($admin, $data);
        $student = Student::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('student_enrollments')->insert([...$data, 'school_id' => $admin->school_id, 'student_id' => $student->id]);
    }

    public function test_database_rejects_class_section_mismatch(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $other = Section::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('student_enrollments')->insert([...$data, 'school_id' => $admin->school_id, 'section_id' => $other->id]);
    }

    public function test_parent_edits_cannot_invalidate_existing_enrollments(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $this->create($admin, $data);
        $student = Student::findOrFail($data['student_id']);
        $this->actingAs($admin)->put('/students/'.$student->id, ['admission_number' => $student->admission_number, 'first_name' => $student->first_name, 'admission_date' => '2026-04-02'])->assertSessionHasErrors('admission_date');
        app(CurrentSchool::class)->set($admin->school);
        $other = Student::factory()->create();
        $this->create($admin, [...$data, 'student_id' => $other->id, 'roll_number' => 2]);
        $this->put('/academics/sections/'.$data['section_id'], ['name' => 'A', 'capacity' => 1])->assertSessionHasErrors('capacity');
        $this->put('/academics/sections/'.$data['section_id'], ['name' => 'A', 'capacity' => 2])->assertRedirect();
    }

    public function test_database_rejects_cross_school_student(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $student = Student::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('student_enrollments')->insert([...$data, 'school_id' => $admin->school_id, 'student_id' => $student->id]);
    }

    public function test_audit_failure_rolls_back_status_transition(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $record = $this->create($admin, $data);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(EnrollmentService::class)->setStatus($admin, $record, EnrollmentStatus::Inactive);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }
        $this->assertDatabaseHas('student_enrollments', ['id' => $record->id, 'status' => 'ACTIVE']);
    }

    public function test_reactivation_rechecks_student_and_parent_status(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $record = $this->create($admin, $data);
        $this->actingAs($admin)->post('/enrollments/'.$record->id.'/deactivate')->assertRedirect();
        foreach (['students' => 'student_id', 'classes' => 'class_id', 'sections' => 'section_id'] as $table => $field) {
            DB::table($table)->where('id', $data[$field])->update(['status' => 'INACTIVE']);
            $this->post('/enrollments/'.$record->id.'/activate')->assertSessionHasErrors('context');
            DB::table($table)->where('id', $data[$field])->update(['status' => 'ACTIVE']);
        }
        $this->post('/enrollments/'.$record->id.'/activate')->assertRedirect();
    }

    public function test_service_prevents_moving_an_existing_enrollment(): void
    {
        $admin = $this->admin();
        $data = $this->context($admin);
        $record = $this->create($admin, $data);
        $this->expectException(ValidationException::class);
        app(EnrollmentService::class)->save($admin,$record,$data);
    }
}

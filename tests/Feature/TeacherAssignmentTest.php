<?php

namespace Tests\Feature;

use App\Enums\TeacherAssignmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\EmployeeAccountLinker;
use App\Services\ProvisionSchool;
use App\Services\TeacherAssignmentAccess;
use App\Services\TeacherAssignmentService;
use App\Support\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/academics/teacher-assignments';

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('DEMO', 'Demo School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function context(User $owner, string $status = 'ACTIVE', bool $curriculum = true): array
    {
        app(CurrentSchool::class)->set($owner->school);
        $year = AcademicYear::create(['name' => fake()->unique()->bothify('Year-########'), 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        $year->status = $status;
        $year->save();
        $class = SchoolClass::factory()->create();
        $teacher = TeacherProfile::factory()->create();
        $section = Section::factory()->create(['class_id' => $class->id]);
        $subject = Subject::factory()->create();
        if ($curriculum) {
            ClassSubject::factory()->create(['academic_year_id' => $year->id, 'class_id' => $class->id, 'subject_id' => $subject->id]);
        }

        return ['assignment_type' => 'SUBJECT_TEACHER', 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'class_id' => $class->id, 'section_id' => $section->id, 'subject_id' => $subject->id];
    }

    private function roleUser(User $admin, array $permissions, ?int $employeeId = null): User
    {
        app(CurrentSchool::class)->set($admin->school);
        $user = User::factory()->create(['school_id' => $admin->school_id, 'employee_id' => $employeeId]);
        $role = Role::create(['name' => 'TEST_'.fake()->unique()->numerify('########')]);
        app(AccessManager::class)->setPermissions($admin, $role, DB::table('permissions')->whereIn('name', $permissions)->pluck('id')->all());
        app(AccessManager::class)->assignRoles($admin, $user, [$role->id]);

        return $user;
    }

    private function teacherUser(User $admin, array $context, array $permissions = ['marks.enter', 'marks.update', 'marks.verify']): User
    {
        $employeeId = DB::table('teacher_profiles')->where('id', $context['teacher_id'])->value('employee_id');
        $user = $this->roleUser($admin, $permissions);
        app(EmployeeAccountLinker::class)->link($admin, $user, $employeeId);

        return $user;
    }

    public function test_guest_and_unprivileged_accounts_are_denied(): void
    {
        $this->get($this->base)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($this->base)->assertForbidden();
        $this->get($this->base.'/create')->assertForbidden();
        $this->post($this->base, [])->assertForbidden();
    }

    public function test_subject_assignment_wizard_create_show_and_audit(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $this->actingAs($admin)->get($this->base.'/create')->assertOk()->assertSee('Choose assignment type');
        $selection = ['assignment_type' => 'SUBJECT_TEACHER'];
        foreach (['academic_year_id', 'teacher_id', 'class_id'] as $field) {
            $this->get($this->base.'/create?'.http_build_query($selection))->assertOk();
            $selection[$field] = $ids[$field];
        }
        $this->get($this->base.'/create?'.http_build_query($selection))->assertOk()->assertSee('Whole class');
        $selection['scope'] = 'SECTION';
        $selection['section_id'] = $ids['section_id'];
        $this->get($this->base.'/create?'.http_build_query($selection))->assertOk()->assertSee('Choose subject');
        $selection['subject_id'] = $ids['subject_id'];
        $this->get($this->base.'/create?'.http_build_query($selection))->assertOk()->assertSee('Confirm teacher assignment');
        $this->post($this->base, $ids)->assertRedirect();
        $id = DB::table('teacher_assignments')->value('id');
        $this->get($this->base.'/'.$id)->assertOk()->assertSee('Currently effective');
        $this->assertDatabaseHas('teacher_assignments', ['id' => $id, 'school_id' => $admin->school_id, ...$ids]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TEACHER_ASSIGNMENT_CREATED', 'entity_id' => $id]);
        $this->put($this->base.'/'.$id, $ids)->assertStatus(405);
        $this->delete($this->base.'/'.$id)->assertStatus(405);
    }

    public function test_null_scope_duplicates_are_retained_and_reactivated(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $ids['section_id'] = null;
        $assignment = app(TeacherAssignmentService::class)->create($admin, $ids);
        $this->actingAs($admin)->post($this->base, $ids)->assertSessionHasErrors('duplicate');
        $this->post($this->base.'/'.$assignment->id.'/deactivate')->assertRedirect();
        $this->post($this->base, $ids)->assertSessionHasErrors('duplicate');
        $this->post($this->base.'/'.$assignment->id.'/activate')->assertRedirect();
        $this->assertDatabaseCount('teacher_assignments', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TEACHER_ASSIGNMENT_ACTIVATED', 'entity_id' => $assignment->id]);
    }

    public function test_class_teacher_scope_conflicts_include_whole_class_overlap(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $ids['assignment_type'] = 'CLASS_TEACHER';
        $ids['subject_id'] = null;
        $a = app(TeacherAssignmentService::class)->create($admin, $ids);
        $teacherB = TeacherProfile::factory()->create();
        $sectionB = Section::factory()->create(['class_id' => $ids['class_id'], 'name' => 'B']);
        $second = [...$ids, 'teacher_id' => $teacherB->id];
        $this->actingAs($admin)->post($this->base, $second)->assertSessionHasErrors('conflict');
        $this->post($this->base, [...$second, 'section_id' => null])->assertSessionHasErrors('conflict');
        $this->post($this->base, [...$second, 'section_id' => $sectionB->id])->assertRedirect();
        $b = DB::table('teacher_assignments')->where('teacher_id', $teacherB->id)->value('id');
        $this->post($this->base.'/'.$a->id.'/deactivate')->assertRedirect();
        $this->post($this->base, [...$second, 'section_id' => null])->assertSessionHasErrors('conflict');
        $this->post($this->base.'/'.$b.'/deactivate')->assertRedirect();
        $this->post($this->base, [...$second, 'section_id' => null])->assertRedirect();
        $this->post($this->base.'/'.$a->id.'/activate')->assertSessionHasErrors('conflict');
    }

    public function test_subject_co_teaching_is_allowed(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        app(TeacherAssignmentService::class)->create($admin, $ids);
        $otherTeacher = TeacherProfile::factory()->create();
        $this->actingAs($admin)->post($this->base, [...$ids, 'teacher_id' => $otherTeacher->id])->assertRedirect();
        $this->assertDatabaseCount('teacher_assignments', 2);
    }

    public function test_assignment_shape_and_protected_fields_are_validated(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $this->actingAs($admin)->post($this->base, [...$ids, 'assignment_type' => 'CLASS_TEACHER'])->assertSessionHasErrors('subject_id');
        $this->post($this->base, [...$ids, 'subject_id' => null])->assertSessionHasErrors('subject_id');
        $this->post($this->base, [...$ids, 'assignment_type' => 'INVALID', 'school_id' => 999, 'status' => 'INACTIVE', 'class_subject_id' => 1])->assertSessionHasErrors(['assignment_type', 'school_id', 'status', 'class_subject_id']);
    }

    public function test_foreign_and_mismatched_context_references_are_rejected(): void
    {
        $admin = $this->admin();
        $own = $this->context($admin);
        $sameSchoolOther = $this->context($admin);
        $other = User::factory()->create();
        $foreign = $this->context($other);
        $this->actingAs($admin);
        foreach (['academic_year_id', 'teacher_id', 'class_id', 'section_id', 'subject_id'] as $field) {
            $this->post($this->base, [...$own, $field => $foreign[$field]])->assertNotFound();
        }
        $this->post($this->base, [...$own, 'section_id' => $sameSchoolOther['section_id']])->assertNotFound();
        $this->post($this->base, [...$own, 'subject_id' => $sameSchoolOther['subject_id']])->assertNotFound();
        $this->assertDatabaseCount('teacher_assignments', 0);
    }

    public function test_all_inactive_parent_states_block_assignment_creation(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $employeeId = DB::table('teacher_profiles')->where('id', $ids['teacher_id'])->value('employee_id');
        $curriculum = DB::table('class_subjects')->where('academic_year_id', $ids['academic_year_id'])->value('id');
        $this->actingAs($admin);
        foreach (['teacher_profiles' => $ids['teacher_id'], 'employees' => $employeeId, 'classes' => $ids['class_id'], 'sections' => $ids['section_id'], 'subjects' => $ids['subject_id'], 'class_subjects' => $curriculum] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['status' => 'INACTIVE']);
            $this->post($this->base, $ids)->assertSessionHasErrors('eligibility');
            DB::table($table)->where('id', $id)->update(['status' => 'ACTIVE']);
        }
        DB::table('academic_years')->where('id', $ids['academic_year_id'])->update(['status' => 'CLOSED']);
        $this->post($this->base, $ids)->assertSessionHasErrors('academic_year_id');
    }

    public function test_draft_year_assignments_prevent_year_removal_and_closed_years_are_read_only(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin, 'DRAFT', false);
        $ids['assignment_type'] = 'CLASS_TEACHER';
        $ids['subject_id'] = null;
        $assignment = app(TeacherAssignmentService::class)->create($admin, $ids);
        $this->assertFalse(TeacherAssignment::effective()->whereKey($assignment->id)->exists());
        $year = '/academics/academic-years/'.$ids['academic_year_id'];
        $url = $this->base.'/'.$assignment->id;
        $this->actingAs($admin)->delete($year)->assertSessionHasErrors('academic_year');
        $this->post($year.'/activate')->assertRedirect();
        $this->post($year.'/close')->assertRedirect();
        $this->get($url)->assertOk()->assertDontSee('Change assignment status');
        $this->post($url.'/deactivate')->assertSessionHasErrors('academic_year_id');
        $this->post($url.'/activate')->assertSessionHasErrors('academic_year_id');
        $this->assertDatabaseHas('teacher_assignments', ['id' => $assignment->id, 'status' => 'ACTIVE']);
    }

    public function test_read_only_assignment_permissions_and_foreign_routes(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $assignment = app(TeacherAssignmentService::class)->create($admin, $ids);
        $reader = $this->roleUser($admin, ['teacher_assignment.view']);
        $this->actingAs($reader)->get($this->base)->assertOk()->assertDontSee('Add assignment');
        $this->get($this->base.'/'.$assignment->id)->assertOk()->assertDontSee('Change assignment status');
        $this->get($this->base.'/create')->assertForbidden();
        $this->post($this->base, $ids)->assertForbidden();
        $this->post($this->base.'/'.$assignment->id.'/deactivate')->assertForbidden();
        $other = User::factory()->create();
        $otherIds = $this->context($other);
        TeacherAssignment::factory()->create(['academic_year_id' => $otherIds['academic_year_id'], 'teacher_id' => $otherIds['teacher_id'], 'class_id' => $otherIds['class_id']]);
        $foreignId = DB::table('teacher_assignments')->where('school_id', $other->school_id)->value('id');
        $this->actingAs($admin)->get($this->base.'/'.$foreignId)->assertNotFound();
        $this->post($this->base.'/'.$foreignId.'/activate')->assertNotFound();
        $this->get($this->base.'?teacher_id='.$otherIds['teacher_id'])->assertSessionHasErrors('teacher_id');
    }

    public function test_assignment_management_is_independent_of_other_module_permissions(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $manager = $this->roleUser($admin, ['teacher_assignment.view', 'teacher_assignment.manage']);
        $this->actingAs($manager)->get($this->base.'/create?'.http_build_query([...$ids, 'scope' => 'SECTION']))->assertOk();
        $this->post($this->base, $ids)->assertRedirect();
        $this->get('/teachers')->assertForbidden();
        $this->get('/academics/classes')->assertForbidden();
    }

    public function test_account_linking_is_explicit_school_scoped_unique_and_audited(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $employeeId = DB::table('teacher_profiles')->where('id', $ids['teacher_id'])->value('employee_id');
        $account = User::factory()->create(['school_id' => $admin->school_id]);
        $second = User::factory()->create(['school_id' => $admin->school_id]);
        $url = '/administration/users/'.$account->id.'/employee';
        $this->actingAs($admin)->get($url)->assertOk();
        $this->put($url, ['employee_id' => $employeeId])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $account->id, 'employee_id' => $employeeId]);
        $this->put('/administration/users/'.$second->id.'/employee', ['employee_id' => $employeeId])->assertSessionHasErrors('employee_id');
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = Employee::factory()->create();
        $this->put($url, ['employee_id' => $foreign->id])->assertSessionHasErrors('employee_id');
        $this->get('/administration/users/'.$other->id.'/employee')->assertNotFound();
        $this->put($url, ['employee_id' => null])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $account->id, 'employee_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_EMPLOYEE_LINKED', 'entity_id' => $account->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_EMPLOYEE_UNLINKED', 'entity_id' => $account->id]);
    }

    public function test_account_linking_requires_rbac_manage_and_excludes_unavailable_employees(): void
    {
        $admin = $this->admin();
        $available = Employee::factory()->create();
        $inactive = Employee::factory()->create(['status' => 'INACTIVE']);
        $linked = Employee::factory()->create();
        User::factory()->create(['school_id' => $admin->school_id, 'employee_id' => $linked->id]);
        $target = User::factory()->create(['school_id' => $admin->school_id]);
        $url = '/administration/users/'.$target->id.'/employee';
        $this->actingAs($admin)->get($url)->assertOk()->assertSee($available->employee_number)->assertDontSee($inactive->employee_number)->assertDontSee($linked->employee_number);
        $reader = $this->roleUser($admin, ['rbac.view']);
        $this->actingAs($reader)->get($url)->assertForbidden();
        $this->put($url, ['employee_id' => $available->id])->assertForbidden();
        $this->actingAs($admin)->put($url, ['employee_id' => $inactive->id])->assertSessionHasErrors('employee_id');
    }

    public function test_marks_access_requires_permission_mapping_and_exact_subject_scope(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $user = $this->teacherUser($admin, $ids);
        $access = app(TeacherAssignmentAccess::class);
        $args = [$ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $ids['subject_id']];
        $this->assertFalse($access->canEnterMarks($user, ...$args));
        app(TeacherAssignmentService::class)->create($admin, $ids);
        $this->assertTrue($access->canEnterMarks($user, ...$args));
        $this->assertTrue($access->canUpdateSubjectMarks($user, ...$args));
        $b = Section::factory()->create(['class_id' => $ids['class_id'], 'name' => 'B']);
        $this->assertFalse($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], $b->id, $ids['subject_id']));
        $otherSubject = Subject::factory()->create();
        ClassSubject::factory()->create(['academic_year_id' => $ids['academic_year_id'], 'class_id' => $ids['class_id'], 'subject_id' => $otherSubject->id]);
        $this->assertFalse($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $otherSubject->id));
        $whole = app(TeacherAssignmentService::class)->create($admin, [...$ids, 'section_id' => null]);
        $this->assertTrue($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], $b->id, $ids['subject_id']));
        app(TeacherAssignmentService::class)->setStatus($admin, $whole, TeacherAssignmentStatus::Inactive);
        $this->assertFalse($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], $b->id, $ids['subject_id']));
        $this->assertFalse($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], 999999, $ids['subject_id']));
        app(EmployeeAccountLinker::class)->link($admin, $user, null);
        $this->assertFalse($access->canEnterMarks($user, ...$args));
    }

    public function test_class_teacher_review_and_correction_do_not_grant_subject_entry(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $user = $this->teacherUser($admin, $ids);
        app(TeacherAssignmentService::class)->create($admin, [...$ids, 'assignment_type' => 'CLASS_TEACHER', 'subject_id' => null]);
        $access = app(TeacherAssignmentAccess::class);
        $this->assertTrue($access->canVerifyClass($user, $ids['academic_year_id'], $ids['class_id'], $ids['section_id']));
        $this->assertTrue($access->canCorrectClassMarks($user, $ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $ids['subject_id']));
        $this->assertFalse($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $ids['subject_id']));
        $this->assertFalse($access->canEnterMarks($admin, $ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $ids['subject_id']));
    }

    public function test_marks_access_rechecks_permission_and_every_parent_status(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $user = $this->teacherUser($admin, $ids);
        $assignment = app(TeacherAssignmentService::class)->create($admin, $ids);
        $access = app(TeacherAssignmentAccess::class);
        $args = [$ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $ids['subject_id']];
        $employeeId = DB::table('teacher_profiles')->where('id', $ids['teacher_id'])->value('employee_id');
        $curriculum = DB::table('class_subjects')->where('academic_year_id', $ids['academic_year_id'])->value('id');
        foreach (['teacher_profiles' => $ids['teacher_id'], 'employees' => $employeeId, 'classes' => $ids['class_id'], 'sections' => $ids['section_id'], 'subjects' => $ids['subject_id'], 'class_subjects' => $curriculum, 'teacher_assignments' => $assignment->id] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['status' => 'INACTIVE']);
            $this->assertFalse($access->canEnterMarks($user, ...$args));
            DB::table($table)->where('id', $id)->update(['status' => 'ACTIVE']);
        }
        DB::table('academic_years')->where('id', $ids['academic_year_id'])->update(['status' => 'CLOSED']);
        $this->assertFalse($access->canEnterMarks($user, ...$args));
        DB::table('academic_years')->where('id', $ids['academic_year_id'])->update(['status' => 'ACTIVE']);
        $role = $user->roles()->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, []);
        $this->assertFalse($access->canEnterMarks($user, ...$args));
    }

    public function test_draft_planning_does_not_grant_marks_access(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin, 'DRAFT');
        $user = $this->teacherUser($admin, $ids);
        app(TeacherAssignmentService::class)->create($admin, $ids);
        $access = app(TeacherAssignmentAccess::class);
        $this->assertFalse($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $ids['subject_id']));
        DB::table('academic_years')->where('id', $ids['academic_year_id'])->update(['status' => 'ACTIVE']);
        $this->assertTrue($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $ids['subject_id']));
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->assertFalse($access->canEnterMarks($user, $ids['academic_year_id'], $ids['class_id'], $ids['section_id'], $ids['subject_id']));
    }

    public function test_null_scope_identity_is_unique_in_database_even_when_inactive(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $assignment = app(TeacherAssignmentService::class)->create($admin, [...$ids, 'section_id' => null]);
        app(TeacherAssignmentService::class)->setStatus($admin, $assignment, TeacherAssignmentStatus::Inactive);
        $row = DB::table('teacher_assignments')->where('id', $assignment->id)->first();
        $this->expectException(QueryException::class);
        DB::table('teacher_assignments')->insert(['school_id' => $admin->school_id, ...$ids, 'section_id' => null, 'class_subject_id' => $row->class_subject_id, 'status' => 'INACTIVE']);
    }

    public function test_database_rejects_invalid_shape_wrong_section_and_curriculum_context(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $other = $this->context($admin);
        $curriculum = DB::table('class_subjects')->where('academic_year_id', $ids['academic_year_id'])->value('id');
        $foreignOwner = User::factory()->create();
        $foreign = $this->context($foreignOwner);
        $base = ['school_id' => $admin->school_id, ...$ids, 'class_subject_id' => $curriculum];
        foreach ([['assignment_type' => 'CLASS_TEACHER'], ['section_id' => $other['section_id']], ['academic_year_id' => $other['academic_year_id']], ['teacher_id' => $foreign['teacher_id']]] as $bad) {
            try {
                DB::table('teacher_assignments')->insert([...$base, ...$bad]);
                $this->fail('Invalid context accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('teacher_assignments', 0);
    }

    public function test_database_rejects_cross_school_employee_account_links(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $employee = Employee::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $admin->id)->update(['employee_id' => $employee->id]);
    }

    public function test_assignment_list_and_account_picker_paginate_and_retain_filters(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        for ($i = 0; $i < 23; $i++) {
            $teacher = TeacherProfile::factory()->create();
            app(TeacherAssignmentService::class)->create($admin, [...$ids, 'teacher_id' => $teacher->id]);
        }
        Employee::factory()->count(23)->create(['first_name' => 'Picker']);
        $account = User::factory()->create(['school_id' => $admin->school_id]);
        $this->actingAs($admin)->get($this->base.'?class_id='.$ids['class_id'])->assertOk()->assertViewHas('assignments', fn ($rows) => $rows->count() === 20 && $rows->total() === 23 && str_contains($rows->url(2), 'class_id='));
        $this->get('/administration/users/'.$account->id.'/employee?q=Picker')->assertOk()->assertViewHas('employees', fn ($rows) => $rows->count() === 20 && $rows->total() === 23);
        $this->get($this->base.'/filter?academic_year_id='.$ids['academic_year_id'].'&class_id='.$ids['class_id'])->assertRedirect($this->base.'?academic_year_id='.$ids['academic_year_id'].'&class_id='.$ids['class_id']);
    }

    public function test_audit_failure_rolls_back_assignment_and_employee_link(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $employeeId = DB::table('teacher_profiles')->where('id', $ids['teacher_id'])->value('employee_id');
        $target = User::factory()->create(['school_id' => $admin->school_id]);
        $this->mock(AuditLogger::class)->shouldReceive('record')->twice()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(TeacherAssignmentService::class)->create($admin,$ids);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed',$e->getMessage());
        }
        try {
            app(EmployeeAccountLinker::class)->link($admin,$target,$employeeId);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed',$e->getMessage());
        }
        $this->assertDatabaseCount('teacher_assignments',0);
        $this->assertDatabaseHas('users',['id' => $target->id, 'employee_id' => null]);
    }
}

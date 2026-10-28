<?php

namespace Tests\Feature;

use App\Enums\ExamSubjectStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\ExamSubject;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ClassSubjectService;
use App\Services\ExamService;
use App\Services\ExamSubjectService;
use App\Services\ExamTermService;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExamSubjectTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $code = 'SUBJECTS'): User
    {
        $u = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run($code, 'Exam Subject School', $u->email);
        $u = $u->fresh();
        app(CurrentSchool::class)->set($u->school);

        return $u;
    }

    private function context(User $u, string $status = 'ACTIVE'): array
    {
        app(CurrentSchool::class)->set($u->school);
        $year = AcademicYear::create(['name' => fake()->unique()->bothify('Year-########'), 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        $year->status = $status;
        $year->save();
        $class = SchoolClass::factory()->create();
        $term = app(ExamTermService::class)->save($u, null, ['academic_year_id' => $year->id, 'name' => 'Annual']);
        $exam = app(ExamService::class)->save($u, null, ['academic_year_id' => $year->id, 'exam_term_id' => $term->id, 'class_id' => $class->id, 'name' => 'Annual Examination', 'start_date' => '2027-03-01', 'end_date' => '2027-03-10']);
        $allocation = ClassSubject::factory()->create(['academic_year_id' => $year->id, 'class_id' => $class->id]);

        return compact('year', 'class', 'term', 'exam', 'allocation');
    }

    private function payload(array $c, array $extra = []): array
    {
        return array_replace(['class_subject_id' => $c['allocation']->id, 'theory_max_marks' => '70.00', 'practical_max_marks' => '30.00', 'passing_marks' => '33.00'], $extra);
    }

    private function create(User $u, array $c, array $extra = []): ExamSubject
    {
        app(CurrentSchool::class)->set($u->school);

        return app(ExamSubjectService::class)->save($u, $c['exam'], null, $this->payload($c, $extra));
    }

    private function url(array $c): string
    {
        return '/examinations/exams/'.$c['exam']->id.'/subjects';
    }

    public function test_picker_prefills_curriculum_and_can_create_view_edit_with_audits(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $name = $c['allocation']->subject->name;
        $this->actingAs($u)->get($this->url($c).'/create')->assertOk()->assertSee($name);
        $this->get($this->url($c).'/create?class_subject_id='.$c['allocation']->id)->assertOk()->assertSee('value="100.00"', false)->assertSee('value="33.00"', false);
        $this->post($this->url($c), $this->payload($c))->assertRedirect();
        $id = DB::table('exam_subjects')->value('id');
        $this->get('/examinations/exam-subjects/'.$id)->assertOk()->assertSee('100.00');
        $this->get('/examinations/exam-subjects/'.$id.'/edit')->assertOk();
        $this->put('/examinations/exam-subjects/'.$id, ['theory_max_marks' => '60', 'practical_max_marks' => '40', 'passing_marks' => '40'])->assertRedirect();
        $this->assertDatabaseHas('exam_subjects', ['id' => $id, 'theory_max_marks' => '60.00', 'passing_marks' => '40.00']);
        foreach (['EXAM_SUBJECT_CREATED', 'EXAM_SUBJECT_UPDATED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $id]);
        }$this->get('/examinations/exams/'.$c['exam']->id)->assertOk()->assertSee($name);
        $this->delete('/examinations/exam-subjects/'.$id)->assertStatus(405);
    }

    public function test_theory_only_practical_only_zero_passing_and_decimal_totals(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $one = $this->create($u, $c, ['theory_max_marks' => '99.99', 'practical_max_marks' => '0.01', 'passing_marks' => '100.00']);
        $this->assertSame('100.00', $one->maximumMarks());
        app(ExamSubjectService::class)->save($u, $c['exam'], $one, ['theory_max_marks' => null, 'practical_max_marks' => '100', 'passing_marks' => '0']);
        $one = $one->fresh();
        $this->assertNull($one->theory_max_marks);
        $this->assertSame('100.00', $one->maximumMarks());
        $this->assertSame('0.00', $one->passing_marks);
        app(ExamSubjectService::class)->save($u, $c['exam'], $one, ['theory_max_marks' => '100', 'practical_max_marks' => null, 'passing_marks' => '33']);
        $this->assertSame('100.00', $one->fresh()->maximumMarks());
    }

    public function test_invalid_missing_zero_negative_precision_and_passing_marks_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->actingAs($u);
        foreach ([['theory_max_marks' => null, 'practical_max_marks' => null], ['theory_max_marks' => 0, 'practical_max_marks' => 0], ['theory_max_marks' => -1], ['theory_max_marks' => '70.001'], ['practical_max_marks' => '10000'], ['passing_marks' => null], ['passing_marks' => 101], ['passing_marks' => -1]] as $marks) {
            $this->post($this->url($c), $this->payload($c, $marks))->assertSessionHasErrors();
        }$this->assertDatabaseCount('exam_subjects', 0);
    }

    public function test_defaults_are_copied_once_and_do_not_track_curriculum_changes(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = app(ExamSubjectService::class)->save($u, $c['exam'], null, ['class_subject_id' => $c['allocation']->id]);
        $this->assertSame('100.00', $record->theory_max_marks);
        app(ClassSubjectService::class)->save($u, $c['allocation'], ['theory_max_marks' => '80', 'practical_max_marks' => '20', 'passing_marks' => '30']);
        $this->assertSame('100.00', $record->fresh()->theory_max_marks);
        $this->assertSame('33.00', $record->fresh()->passing_marks);
    }

    public function test_missing_curriculum_defaults_require_explicit_exam_configuration(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $c['allocation']->theory_max_marks = null;
        $c['allocation']->passing_marks = null;
        $c['allocation']->save();
        $this->actingAs($u)->post($this->url($c), ['class_subject_id' => $c['allocation']->id])->assertSessionHasErrors('passing_marks');
        $this->post($this->url($c), $this->payload($c, ['theory_max_marks' => '100', 'practical_max_marks' => null]))->assertRedirect();
    }

    public function test_duplicate_and_inactive_identity_is_retained(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = $this->create($u, $c);
        $this->actingAs($u)->post($this->url($c), $this->payload($c))->assertSessionHasErrors('class_subject_id');
        $this->post('/examinations/exam-subjects/'.$record->id.'/deactivate')->assertRedirect();
        $this->post($this->url($c), $this->payload($c))->assertSessionHasErrors('class_subject_id');
        $this->get($this->url($c).'/create')->assertOk()->assertViewHas('allocations', fn ($rows) => $rows->total() === 0);
        $this->post('/examinations/exam-subjects/'.$record->id.'/activate')->assertRedirect();
        $this->assertDatabaseCount('exam_subjects', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXAM_SUBJECT_DEACTIVATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXAM_SUBJECT_ACTIVATED']);
    }

    public function test_exam_and_subject_context_cannot_be_changed(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = $this->create($u, $c);
        $this->actingAs($u)->put('/examinations/exam-subjects/'.$record->id, [...$this->payload($c), 'exam_id' => $c['exam']->id, 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'subject_id' => $c['allocation']->subject_id, 'school_id' => $u->school_id, 'status' => 'INACTIVE'])->assertSessionHasErrors(['class_subject_id', 'exam_id', 'academic_year_id', 'class_id', 'subject_id', 'school_id', 'status']);
    }

    public function test_other_class_and_year_allocations_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $other = $this->context($u);
        $wrongClass = ClassSubject::factory()->create(['academic_year_id' => $c['year']->id]);
        $this->actingAs($u)->post($this->url($c), $this->payload($c, ['class_subject_id' => $other['allocation']->id]))->assertSessionHasErrors('class_subject_id');
        $this->post($this->url($c), $this->payload($c, ['class_subject_id' => $wrongClass->id]))->assertSessionHasErrors('class_subject_id');
        $this->get($this->url($c).'/create?class_subject_id='.$other['allocation']->id)->assertNotFound();
    }

    public function test_inactive_allocation_subject_term_and_class_cannot_be_added(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->actingAs($u);
        foreach (['class_subjects' => $c['allocation']->id, 'subjects' => $c['allocation']->subject_id, 'exam_terms' => $c['term']->id, 'classes' => $c['class']->id] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['status' => 'INACTIVE']);
            $this->post($this->url($c), $this->payload($c))->assertSessionHasErrors('context');
            DB::table($table)->where('id', $id)->update(['status' => 'ACTIVE']);
        }$this->assertDatabaseCount('exam_subjects', 0);
    }

    public function test_reactivation_checks_current_parent_status(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = $this->create($u, $c);
        app(ExamSubjectService::class)->setStatus($u, $record, ExamSubjectStatus::Inactive);
        $this->actingAs($u);
        foreach (['class_subjects' => $c['allocation']->id, 'subjects' => $c['allocation']->subject_id, 'exam_terms' => $c['term']->id, 'classes' => $c['class']->id] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['status' => 'INACTIVE']);
            $this->post('/examinations/exam-subjects/'.$record->id.'/activate')->assertSessionHasErrors('context');
            DB::table($table)->where('id', $id)->update(['status' => 'ACTIVE']);
        }$this->post('/examinations/exam-subjects/'.$record->id.'/activate')->assertRedirect();
    }

    public function test_open_requires_an_active_configured_subject(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->actingAs($u)->post('/examinations/exams/'.$c['exam']->id.'/open')->assertSessionHasErrors('subjects');
        $record = $this->create($u, $c);
        app(ExamSubjectService::class)->setStatus($u, $record, ExamSubjectStatus::Inactive);
        $this->post('/examinations/exams/'.$c['exam']->id.'/open')->assertSessionHasErrors('subjects');
        app(CurrentSchool::class)->set($u->school);
        app(ExamSubjectService::class)->setStatus($u, $record, ExamSubjectStatus::Active);
        $this->post('/examinations/exams/'.$c['exam']->id.'/open')->assertRedirect();
    }

    public function test_open_rechecks_all_active_curriculum_and_subjects(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->create($u, $c);
        $this->actingAs($u);
        foreach (['class_subjects' => $c['allocation']->id, 'subjects' => $c['allocation']->subject_id] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['status' => 'INACTIVE']);
            $this->post('/examinations/exams/'.$c['exam']->id.'/open')->assertSessionHasErrors('subjects');
            DB::table($table)->where('id', $id)->update(['status' => 'ACTIVE']);
        }$this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'DRAFT']);
    }

    public function test_open_and_later_states_freeze_configuration(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = $this->create($u, $c);
        $this->actingAs($u);
        foreach (['OPEN', 'MARKS_ENTRY', 'VERIFICATION', 'APPROVED', 'PUBLISHED', 'CLOSED'] as $status) {
            DB::table('exams')->where('id', $c['exam']->id)->update(['status' => $status]);
            $this->get('/examinations/exam-subjects/'.$record->id)->assertOk()->assertDontSee('Edit marks configuration');
            $this->get($this->url($c).'/create')->assertForbidden();
            $this->put('/examinations/exam-subjects/'.$record->id, ['theory_max_marks' => 100, 'passing_marks' => 33])->assertForbidden();
            $this->post('/examinations/exam-subjects/'.$record->id.'/deactivate')->assertForbidden();
        }
    }

    public function test_draft_year_planning_and_closed_year_read_only(): void
    {
        $u = $this->admin();
        $c = $this->context($u, 'DRAFT');
        $record = $this->create($u, $c);
        $this->actingAs($u)->get('/examinations/exam-subjects/'.$record->id.'/edit')->assertOk();
        foreach (['CLOSED', 'ARCHIVED'] as $status) {
            DB::table('academic_years')->where('id', $c['year']->id)->update(['status' => $status]);
            $this->get('/examinations/exam-subjects/'.$record->id)->assertOk();
            $this->get('/examinations/exam-subjects/'.$record->id.'/edit')->assertForbidden();
            $this->post('/examinations/exam-subjects/'.$record->id.'/activate')->assertForbidden();
        }
    }

    public function test_view_only_and_create_only_users_cannot_manage_exam_subjects(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = $this->create($u, $c);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        $reader = User::factory()->create(['school_id' => $u->school_id]);
        app(AccessManager::class)->setPermissions($u, $role, DB::table('permissions')->whereIn('name', ['exam.view', 'exam.create'])->pluck('id')->all());
        app(AccessManager::class)->assignRoles($u, $reader, [$role->id]);
        $this->actingAs($reader)->get('/examinations/exam-subjects')->assertOk();
        $this->get('/examinations/exam-subjects/'.$record->id)->assertOk()->assertDontSee('Edit marks configuration');
        $this->get($this->url($c).'/create')->assertForbidden();
        $this->post($this->url($c), $this->payload($c))->assertForbidden();
        $this->put('/examinations/exam-subjects/'.$record->id, ['passing_marks' => 33])->assertForbidden();
        $this->post('/examinations/exam-subjects/'.$record->id.'/activate')->assertForbidden();
        $this->post('/logout');
        $this->get('/examinations/exam-subjects')->assertRedirect('/login');
    }

    public function test_cross_school_endpoints_payload_and_service_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $other = $this->admin('OTHER');
        $foreign = $this->context($other);
        $record = $this->create($other, $foreign);
        $foreignName = $record->subject->name;
        $this->actingAs($u)->get('/examinations/exam-subjects')->assertOk()->assertDontSee($foreignName);
        foreach (['', '/edit'] as $suffix) {
            $this->get('/examinations/exam-subjects/'.$record->id.$suffix)->assertNotFound();
        }$this->post('/examinations/exam-subjects/'.$record->id.'/activate')->assertNotFound();
        $this->post($this->url($c), $this->payload($c, ['class_subject_id' => $foreign['allocation']->id]))->assertSessionHasErrors('class_subject_id');
        $this->get($this->url($foreign).'/create')->assertNotFound();
        app(CurrentSchool::class)->set($u->school);
        $this->expectException(AuthorizationException::class);
        app(ExamSubjectService::class)->save($u, $foreign['exam'], $record, ['passing_marks' => 33]);
    }

    public function test_direct_service_rejects_other_exam_and_stale_open_state(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $other = $this->context($u);
        $record = $this->create($u, $c);
        try {
            app(ExamSubjectService::class)->save($u, $other['exam'], $record, ['passing_marks' => 33]);
            $this->fail('Expected mismatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('exam_id', $e->errors());
        }app(ExamService::class)->transition($u, $c['exam'], 'open');
        $this->expectException(AuthorizationException::class);
        app(ExamSubjectService::class)->save($u, $c['exam'], $record, ['passing_marks' => 40]);
    }

    public function test_listing_picker_and_exam_details_are_paginated_with_filters(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        foreach (range(1, 23) as $i) {
            $subject = Subject::factory()->create(['name' => 'Searchable '.$i]);
            $allocation = ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'subject_id' => $subject->id]);
            $this->create($u, [...$c, 'allocation' => $allocation]);
        }
        $this->actingAs($u)->get('/examinations/exam-subjects?q=Searchable&status=ACTIVE')->assertOk()->assertViewHas('subjects', fn ($rows) => $rows->total() === 23 && $rows->count() === 20 && str_contains($rows->url(2), 'status=ACTIVE'));
        $this->get('/examinations/exams/'.$c['exam']->id)->assertOk()->assertViewHas('subjects', fn ($rows) => $rows->total() === 23 && str_contains($rows->url(2), 'subjects_page=2'));
        $this->get($this->url($c).'/create')->assertOk()->assertViewHas('allocations', fn ($rows) => $rows->total() === 1);
        $this->get('/examinations/exam-subjects?status=INVALID')->assertSessionHasErrors('status');
    }

    public function test_failed_audit_rolls_back_creation_and_status_change(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = $this->create($u, $c);
        $allocation = ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id]);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit failed'));
        foreach (['create', 'status'] as $action) {
            try {
                if ($action === 'create') {
                    $this->create($u, [...$c, 'allocation' => $allocation]);
                } else {
                    app(ExamSubjectService::class)->setStatus($u, $record, ExamSubjectStatus::Inactive);
                }$this->fail('Expected rollback.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Audit failed', $e->getMessage());
            }
        }$this->assertDatabaseCount('exam_subjects', 1);
        $this->assertDatabaseHas('exam_subjects', ['id' => $record->id, 'status' => 'ACTIVE']);
    }

    public function test_noop_save_and_status_do_not_duplicate_audits(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = $this->create($u, $c);
        $count = DB::table('audit_logs')->count();
        app(ExamSubjectService::class)->save($u, $c['exam'], $record, []);
        app(ExamSubjectService::class)->setStatus($u, $record, ExamSubjectStatus::Active);
        $this->assertDatabaseCount('audit_logs', $count);
    }

    public function test_database_rejects_duplicate_exam_subject(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $record = $this->create($u, $c);
        $data = DB::table('exam_subjects')->where('id', $record->id)->first();
        $insert = (array) $data;
        unset($insert['id']);
        $this->expectException(QueryException::class);
        DB::table('exam_subjects')->insert($insert);
    }

    public function test_database_rejects_mismatched_exam_curriculum_context(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $other = $this->context($u);
        $this->expectException(QueryException::class);
        DB::table('exam_subjects')->insert(['school_id' => $u->school_id, 'exam_id' => $c['exam']->id, 'class_subject_id' => $other['allocation']->id, 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'subject_id' => $other['allocation']->subject_id, 'theory_max_marks' => 100, 'passing_marks' => 33]);
    }

    public function test_database_rejects_passing_marks_above_total(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->expectException(QueryException::class);
        DB::table('exam_subjects')->insert(['school_id' => $u->school_id, 'exam_id' => $c['exam']->id, 'class_subject_id' => $c['allocation']->id, 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'subject_id' => $c['allocation']->subject_id, 'theory_max_marks' => 70, 'practical_max_marks' => 30, 'passing_marks' => 101]);
    }
}

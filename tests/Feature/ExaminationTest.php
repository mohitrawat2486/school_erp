<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
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

class ExaminationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $code = 'EXAMS'): User
    {
        $u = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run($code, 'Exam School', $u->email);
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
        $term = app(ExamTermService::class)->save($u, null, ['academic_year_id' => $year->id, 'name' => 'Half Yearly']);

        return compact('year', 'class', 'term');
    }

    private function data(array $c, array $extra = []): array
    {
        return array_replace(['academic_year_id' => $c['year']->id, 'exam_term_id' => $c['term']->id, 'class_id' => $c['class']->id, 'name' => 'Half Yearly Examination', 'start_date' => '2026-09-01', 'end_date' => '2026-09-10'], $extra);
    }

    private function exam(User $u, array $c): Exam
    {
        app(CurrentSchool::class)->set($u->school);

        $exam = app(ExamService::class)->save($u, null, $this->data($c));
        $allocation = ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id]);
        app(ExamSubjectService::class)->save($u, $exam, null, ['class_subject_id' => $allocation->id]);

        return $exam;
    }

    private function reader(User $u, array $permissions): User
    {
        app(CurrentSchool::class)->set($u->school);
        $user = User::factory()->create(['school_id' => $u->school_id]);
        $role = Role::create(['name' => fake()->unique()->bothify('EXAM-########')]);
        app(AccessManager::class)->setPermissions($u, $role, DB::table('permissions')->whereIn('name', $permissions)->pluck('id')->all());
        app(AccessManager::class)->assignRoles($u, $user, [$role->id]);

        return $user;
    }

    public function test_term_creation_editing_status_and_audits(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->actingAs($u)->get('/examinations/terms')->assertOk();
        $this->get('/examinations/terms/create?academic_year_id='.$c['year']->id)->assertOk()->assertSee('Create Exam Term');
        $this->post('/examinations/terms', ['academic_year_id' => $c['year']->id, 'name' => 'Annual'])->assertRedirect();
        $id = DB::table('exam_terms')->where('name', 'Annual')->value('id');
        $this->get('/examinations/terms/'.$id)->assertOk()->assertSee('Annual');
        $this->get('/examinations/terms/'.$id.'/edit')->assertOk();
        $this->put('/examinations/terms/'.$id, ['name' => 'Annual Exam'])->assertRedirect();
        $this->post('/examinations/terms/'.$id.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('exam_terms', ['id' => $id, 'status' => 'INACTIVE']);
        $this->post('/examinations/terms/'.$id.'/activate')->assertRedirect();
        foreach (['EXAM_TERM_CREATED', 'EXAM_TERM_UPDATED', 'EXAM_TERM_DEACTIVATED', 'EXAM_TERM_ACTIVATED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $id]);
        }
        $this->delete('/examinations/terms/'.$id)->assertStatus(405);
    }

    public function test_exam_creation_draft_editing_and_audits(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->actingAs($u)->post('/examinations/exams', $this->data($c))->assertRedirect();
        $id = DB::table('exams')->value('id');
        $this->get('/examinations/exams')->assertOk()->assertSee('Half Yearly Examination');
        $this->get('/examinations/exams/'.$id)->assertOk()->assertSee('DRAFT');
        $this->get('/examinations/exams/'.$id.'/edit')->assertOk();
        $this->put('/examinations/exams/'.$id, ['name' => 'Updated', 'start_date' => '2026-09-02', 'end_date' => '2026-09-11'])->assertRedirect();
        $this->assertDatabaseHas('exams', ['id' => $id, 'name' => 'Updated', 'status' => 'DRAFT']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXAM_CREATED', 'entity_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXAM_UPDATED', 'entity_id' => $id]);
        $this->delete('/examinations/exams/'.$id)->assertStatus(405);
    }

    public function test_exam_selection_is_paginated_and_contextual(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->actingAs($u)->get('/examinations/exams/create')->assertOk()->assertSee('Choose academic year');
        $params = ['academic_year_id' => $c['year']->id];
        $this->get('/examinations/exams/create?'.http_build_query($params))->assertOk()->assertSee('Choose exam term');
        $params['exam_term_id'] = $c['term']->id;
        $this->get('/examinations/exams/create?'.http_build_query($params))->assertOk()->assertSee('Choose class');
        $params['class_id'] = $c['class']->id;
        $this->get('/examinations/exams/create?'.http_build_query($params))->assertOk()->assertSee('Create Exam');
    }

    public function test_date_name_and_forbidden_fields_are_validated(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->actingAs($u)->post('/examinations/exams', $this->data($c, ['name' => '', 'start_date' => 'invalid', 'end_date' => 'bad', 'school_id' => 999, 'status' => 'PUBLISHED']))->assertSessionHasErrors(['name', 'start_date', 'end_date', 'school_id', 'status']);
        foreach ([['start_date' => '2026-03-31'], ['end_date' => '2027-04-01'], ['end_date' => '2026-08-31']] as $dates) {
            $this->post('/examinations/exams', $this->data($c, $dates))->assertSessionHasErrors();
        }
        $this->post('/examinations/terms', ['academic_year_id' => $c['year']->id, 'name' => '', 'status' => 'INACTIVE'])->assertSessionHasErrors(['name', 'status']);
        $this->assertDatabaseCount('exams', 0);
    }

    public function test_setup_context_cannot_be_moved(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $this->actingAs($u)->put('/examinations/exams/'.$exam->id, $this->data($c))->assertSessionHasErrors(Exam::CONTEXT);
        $this->put('/examinations/terms/'.$c['term']->id, ['name' => 'Updated', 'academic_year_id' => $c['year']->id])->assertSessionHasErrors('academic_year_id');
    }

    public function test_term_names_are_unique_per_year_and_exam_names_per_term_and_class(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->exam($u, $c);
        $this->actingAs($u)->post('/examinations/terms', ['academic_year_id' => $c['year']->id, 'name' => 'Half Yearly'])->assertSessionHasErrors('name');
        $this->post('/examinations/exams', $this->data($c))->assertSessionHasErrors('name');
        app(CurrentSchool::class)->set($u->school);
        $otherClass = SchoolClass::factory()->create();
        $this->post('/examinations/exams', $this->data($c, ['class_id' => $otherClass->id]))->assertRedirect();
        $this->assertDatabaseCount('exams', 2);
    }

    public function test_open_freezes_setup_return_to_draft_and_close_are_controlled(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $base = '/examinations/exams/'.$exam->id;
        $this->actingAs($u)->post($base.'/open')->assertRedirect();
        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'status' => 'OPEN']);
        $this->get($base)->assertOk()->assertDontSee('Edit exam');
        $this->put($base, ['name' => 'Forbidden', 'start_date' => '2026-09-01', 'end_date' => '2026-09-10'])->assertForbidden();
        $this->post($base.'/open')->assertSessionHasErrors('action');
        $this->post($base.'/draft')->assertRedirect();
        $this->get($base.'/edit')->assertOk();
        $this->post($base.'/close')->assertRedirect();
        $this->post($base.'/open')->assertSessionHasErrors('action');
        $this->post($base.'/draft')->assertSessionHasErrors('action');
        foreach (['EXAM_OPEN', 'EXAM_DRAFT', 'EXAM_CLOSE'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_future_workflow_states_cannot_be_reached_through_setup(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $this->actingAs($u)->post('/examinations/exams/'.$exam->id.'/publish')->assertNotFound();
        app(CurrentSchool::class)->set($u->school);
        $this->expectException(ValidationException::class);
        app(ExamService::class)->transition($u, $exam, 'publish');
    }

    public function test_draft_year_supports_planning_but_cannot_open_exam(): void
    {
        $u = $this->admin();
        $c = $this->context($u, 'DRAFT');
        $exam = $this->exam($u, $c);
        $this->actingAs($u)->post('/examinations/exams/'.$exam->id.'/open')->assertSessionHasErrors('action');
        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'status' => 'DRAFT']);
    }

    public function test_closed_and_archived_years_are_read_only(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $this->actingAs($u);
        foreach (['CLOSED', 'ARCHIVED'] as $status) {
            DB::table('academic_years')->where('id', $c['year']->id)->update(['status' => $status]);
            $this->get('/examinations/exams/'.$exam->id)->assertOk()->assertDontSee('Edit exam');
            $this->get('/examinations/terms/'.$c['term']->id.'/edit')->assertForbidden();
            $this->put('/examinations/exams/'.$exam->id, ['name' => 'Updated', 'start_date' => '2026-09-01', 'end_date' => '2026-09-10'])->assertForbidden();
            $this->post('/examinations/exams/'.$exam->id.'/open')->assertForbidden();
            $this->post('/examinations/terms/'.$c['term']->id.'/deactivate')->assertForbidden();
            $this->post('/examinations/exams', $this->data($c))->assertSessionHasErrors('academic_year_id');
        }
    }

    public function test_year_closure_requires_closed_exams_and_preserves_history(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $this->actingAs($u)->post('/academics/academic-years/'.$c['year']->id.'/close')->assertSessionHasErrors('academic_year');
        $this->post('/examinations/exams/'.$exam->id.'/close')->assertRedirect();
        $this->post('/academics/academic-years/'.$c['year']->id.'/close')->assertRedirect();
        $this->get('/examinations/exams/'.$exam->id)->assertOk()->assertSee('CLOSED');
    }

    public function test_draft_year_dates_and_deletion_preserve_exam_setup(): void
    {
        $u = $this->admin();
        $c = $this->context($u, 'DRAFT');
        $this->exam($u, $c);
        $this->actingAs($u)->put('/academics/academic-years/'.$c['year']->id, ['name' => $c['year']->name, 'start_date' => '2026-10-01', 'end_date' => '2027-03-31'])->assertSessionHasErrors('academic_year');
        $this->delete('/academics/academic-years/'.$c['year']->id)->assertSessionHasErrors('academic_year');
        $this->assertDatabaseHas('academic_years', ['id' => $c['year']->id, 'deleted_at' => null]);
    }

    public function test_inactive_terms_classes_and_wrong_year_terms_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $other = $this->context($u);
        $this->actingAs($u)->post('/examinations/exams', $this->data($c, ['exam_term_id' => $other['term']->id]))->assertSessionHasErrors('exam_term_id');
        DB::table('exam_terms')->where('id', $c['term']->id)->update(['status' => 'INACTIVE']);
        $this->post('/examinations/exams', $this->data($c))->assertSessionHasErrors('context');
        DB::table('exam_terms')->where('id', $c['term']->id)->update(['status' => 'ACTIVE']);
        DB::table('classes')->where('id', $c['class']->id)->update(['status' => 'INACTIVE']);
        $this->post('/examinations/exams', $this->data($c))->assertSessionHasErrors('context');
    }

    public function test_term_deactivation_and_rename_protect_released_exams(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $this->actingAs($u)->post('/examinations/exams/'.$exam->id.'/open')->assertRedirect();
        $this->post('/examinations/terms/'.$c['term']->id.'/deactivate')->assertSessionHasErrors('term');
        $this->put('/examinations/terms/'.$c['term']->id, ['name' => 'Changed'])->assertSessionHasErrors('name');
        $this->post('/examinations/exams/'.$exam->id.'/draft')->assertRedirect();
        $this->post('/examinations/terms/'.$c['term']->id.'/deactivate')->assertRedirect();
        $this->post('/examinations/exams/'.$exam->id.'/open')->assertSessionHasErrors('context');
        $this->post('/examinations/exams/'.$exam->id.'/close')->assertRedirect();
    }

    public function test_read_only_create_only_and_publish_only_permissions_are_distinct(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $reader = $this->reader($u, ['exam.view']);
        $creator = $this->reader($u, ['exam.view', 'exam.create']);
        $publisher = $this->reader($u, ['exam.view', 'exam.publish']);
        $this->actingAs($reader)->get('/examinations/exams')->assertOk()->assertDontSee('Add exam');
        $this->get('/examinations/exams/create')->assertForbidden();
        $this->post('/examinations/exams', $this->data($c))->assertForbidden();
        $this->post('/examinations/terms/'.$c['term']->id.'/deactivate')->assertForbidden();
        $this->post('/examinations/exams/'.$exam->id.'/open')->assertForbidden();
        $this->actingAs($creator)->get('/examinations/exams/create')->assertOk();
        $this->post('/examinations/exams/'.$exam->id.'/open')->assertForbidden();
        $this->actingAs($publisher)->post('/examinations/exams/'.$exam->id.'/open')->assertForbidden();
    }

    public function test_guests_and_accounts_without_exam_view_are_denied(): void
    {
        $this->get('/examinations/exams')->assertRedirect('/login');
        $this->get('/examinations/terms')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/examinations/exams')->assertForbidden();
        $this->get('/examinations/terms')->assertForbidden();
    }

    public function test_foreign_ids_do_not_resolve_and_mixed_payloads_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $foreignOwner = $this->admin('FOREIGN');
        $foreign = $this->context($foreignOwner);
        $exam = $this->exam($foreignOwner, $foreign);
        $this->actingAs($u)->get('/examinations/exams')->assertOk()->assertDontSee($foreign['class']->name);
        foreach (['', '/edit'] as $suffix) {
            $this->get('/examinations/exams/'.$exam->id.$suffix)->assertNotFound();
        }
        $this->post('/examinations/exams/'.$exam->id.'/open')->assertNotFound();
        $this->get('/examinations/terms/'.$foreign['term']->id)->assertNotFound();
        foreach (Exam::CONTEXT as $field) {
            $this->post('/examinations/exams', $this->data($c, [$field => $this->data($foreign)[$field]]))->assertSessionHasErrors($field);
        }
        $this->get('/examinations/exams/create?academic_year_id='.$foreign['year']->id)->assertNotFound();
        app(CurrentSchool::class)->set($u->school);
        $this->expectException(AuthorizationException::class);
        app(ExamService::class)->transition($u, $exam, 'close');
    }

    public function test_listing_search_status_pagination_and_term_details(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        for ($i = 1; $i <= 23; $i++) {
            app(ExamService::class)->save($u, null, $this->data($c, ['name' => 'Searchable '.$i]));
        }
        $this->actingAs($u)->get('/examinations/exams?q=Searchable&status=DRAFT')->assertOk()->assertViewHas('exams', fn ($rows) => $rows->total() === 23 && $rows->count() === 20 && str_contains($rows->url(2), 'status=DRAFT'));
        $this->get('/examinations/terms/'.$c['term']->id)->assertOk()->assertViewHas('exams', fn ($rows) => $rows->total() === 23);
        $this->get('/examinations/terms?q=Half%20Yearly&status=ACTIVE')->assertOk()->assertSee('Half Yearly');
        $this->get('/examinations/exams?status=INVALID')->assertSessionHasErrors('status');
    }

    public function test_audit_failure_rolls_back_exam_and_term_creation(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit failed'));
        foreach (['exam', 'term'] as $kind) {
            try {
                if ($kind === 'exam') {
                    app(ExamService::class)->save($u, null, $this->data($c));
                } else {
                    app(ExamTermService::class)->save($u, null, ['academic_year_id' => $c['year']->id, 'name' => 'Annual']);
                }
                $this->fail('Expected rollback.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Audit failed', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('exams', 0);
        $this->assertDatabaseCount('exam_terms', 1);
    }

    public function test_audit_failure_rolls_back_status_transition(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(ExamService::class)->transition($u, $exam, 'open');
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }
        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'status' => 'DRAFT']);
    }

    public function test_database_rejects_cross_year_term(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $other = $this->context($u);
        $this->expectException(QueryException::class);
        DB::table('exams')->insert([...$this->data($c, ['exam_term_id' => $other['term']->id]), 'school_id' => $u->school_id]);
    }

    public function test_database_rejects_duplicate_exam_identity(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->exam($u, $c);
        $this->expectException(QueryException::class);
        DB::table('exams')->insert([...$this->data($c), 'school_id' => $u->school_id]);
    }

    public function test_database_rejects_invalid_exam_dates(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->expectException(QueryException::class);
        DB::table('exams')->insert([...$this->data($c, ['end_date' => '2026-08-01']), 'school_id' => $u->school_id]);
    }

    public function test_future_states_cannot_be_reversed_or_closed_through_setup(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $this->actingAs($u);
        foreach (['MARKS_ENTRY', 'VERIFICATION', 'APPROVED', 'PUBLISHED'] as $status) {
            DB::table('exams')->where('id', $exam->id)->update(['status' => $status]);
            foreach (['open', 'draft', 'close'] as $action) {
                $this->post('/examinations/exams/'.$exam->id.'/'.$action)->assertSessionHasErrors('action');
            }$this->assertDatabaseHas('exams', ['id' => $exam->id, 'status' => $status]);
        }
    }

    public function test_stale_draft_instance_cannot_edit_open_exam(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        app(ExamService::class)->transition($u, $exam, 'open');
        $this->expectException(AuthorizationException::class);
        app(ExamService::class)->save($u, $exam, ['name' => 'Changed', 'start_date' => '2026-09-01', 'end_date' => '2026-09-10']);
    }

    public function test_database_rejects_cross_school_class(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $class = SchoolClass::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('exams')->insert([...$this->data($c, ['class_id' => $class->id]), 'school_id' => $u->school_id]);
    }

    public function test_database_rejects_duplicate_term_identity(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $this->expectException(QueryException::class);
        DB::table('exam_terms')->insert(['school_id' => $u->school_id, 'academic_year_id' => $c['year']->id, 'name' => $c['term']->name]);
    }

    public function test_update_permission_does_not_grant_creation(): void
    {
        $u = $this->admin();
        $c = $this->context($u);
        $exam = $this->exam($u, $c);
        $updater = $this->reader($u, ['exam.view', 'exam.update']);
        $this->actingAs($updater)->get('/examinations/exams/'.$exam->id.'/edit')->assertOk();
        $this->get('/examinations/terms/'.$c['term']->id.'/edit')->assertOk();
        $this->post('/examinations/exams/'.$exam->id.'/open')->assertRedirect();
        $this->get('/examinations/exams/create')->assertForbidden();
        $this->get('/examinations/terms/create')->assertForbidden();
    }
}

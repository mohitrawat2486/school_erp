<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\PromotionBatch;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use App\Services\PromotionService;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $code = 'PROMO'): User
    {
        $u = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run($code, 'Promotion School', $u->email);
        $u = $u->fresh();
        app(CurrentSchool::class)->set($u->school);

        return $u;
    }

    private function year(string $name, string $start, string $end): AcademicYear
    {
        $year = AcademicYear::create(['name' => $name, 'start_date' => $start, 'end_date' => $end]);
        $year->status = 'ACTIVE';
        $year->save();

        return $year;
    }

    private function setupContext(User $u, int $count = 1): array
    {
        app(CurrentSchool::class)->set($u->school);
        $sourceYear = $this->year('2026-27', '2026-04-01', '2027-03-31');
        $targetYear = $this->year('2027-28', '2027-04-01', '2028-03-31');
        $source = Section::factory()->create();
        $target = Section::factory()->create();
        $records = [];
        foreach (range(1, $count) as $roll) {
            $student = Student::factory()->create(['admission_date' => '2026-04-01']);
            $records[] = app(EnrollmentService::class)->save($u, null, ['student_id' => $student->id, 'academic_year_id' => $sourceYear->id, 'class_id' => $source->class_id, 'section_id' => $source->id, 'roll_number' => $roll, 'joined_at' => '2026-04-01']);
        }

        return ['sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'source' => $source, 'target' => $target, 'records' => $records];
    }

    private function payload(array $context, array $overrides = []): array
    {
        return array_replace(['operation_key' => (string) Str::uuid(), 'confirmed' => '1', 'mode' => 'PLACEMENT', 'source_academic_year_id' => $context['sourceYear']->id, 'source_class_id' => $context['source']->class_id, 'source_section_id' => $context['source']->id, 'target_academic_year_id' => $context['targetYear']->id, 'target_class_id' => $context['target']->class_id, 'target_section_id' => $context['target']->id, 'joined_at' => '2027-04-01', 'rows' => array_map(fn ($record) => ['source_enrollment_id' => $record->id, 'action' => 'PROMOTE', 'roll_number' => $record->roll_number + 10, 'notes' => 'Reviewed'], $context['records'])], $overrides);
    }

    private function exitPayload(array $context, string $action = 'WITHDRAW'): array
    {
        $data = $this->payload($context, ['mode' => 'EXIT']);
        foreach (['target_academic_year_id', 'target_class_id', 'target_section_id', 'joined_at'] as $field) {
            unset($data[$field]);
        }foreach ($data['rows'] as &$row) {
            $row['action'] = $action;
            $row['roll_number'] = null;
            $row['notes'] = 'Left school';
        }

        return $data;
    }

    private function process(User $actor, array $data): PromotionBatch
    {
        app(CurrentSchool::class)->set($actor->school);

        return app(PromotionService::class)->process($actor, $data);
    }

    public function test_individual_promotion_creates_new_enrollment_and_preserves_source(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $before = $c['records'][0]->getAttributes();
        $data = $this->payload($c);
        $this->actingAs($u)->post('/promotions', $data)->assertRedirect();
        $batch = DB::table('promotion_batches')->value('id');
        $outcome = DB::table('promotion_outcomes')->first();
        $this->assertDatabaseCount('student_enrollments', 2);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['records'][0]->id, 'academic_year_id' => $c['sourceYear']->id, 'class_id' => $c['source']->class_id, 'roll_number' => 1, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('student_enrollments', ['id' => $outcome->target_enrollment_id, 'academic_year_id' => $c['targetYear']->id, 'class_id' => $c['target']->class_id, 'roll_number' => 11]);
        $this->get('/promotions/'.$batch)->assertOk()->assertSee('PROMOTE');
        $this->get('/enrollments/'.$c['records'][0]->id)->assertOk()->assertSee('View recorded promote outcome');
        $this->get('/enrollments/'.$outcome->target_enrollment_id)->assertOk()->assertSee('View originating promotion');
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_PROMOTE']);
    }

    public function test_bulk_promotion_and_request_retries_are_idempotent(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 3);
        $data = $this->payload($c);
        $batch = $this->process($u, $data);
        $auditCount = DB::table('audit_logs')->count();
        $data['rows'] = array_reverse($data['rows']);
        $again = $this->process($u, $data);
        $this->assertSame($batch->id, $again->id);
        $this->assertDatabaseCount('student_enrollments', 6);
        $this->assertDatabaseCount('promotion_outcomes', 3);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $data['operation_key'] = (string) Str::uuid();
        $this->expectException(ValidationException::class);
        $this->process($u, $data);
    }

    public function test_same_confirmation_cannot_be_used_for_different_payload(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $data = $this->payload($c);
        $this->process($u, $data);
        $data['rows'][0]['roll_number'] = 99;
        $this->expectException(ValidationException::class);
        $this->process($u, $data);
    }

    public function test_repeat_creates_next_year_enrollment_in_same_class_and_new_section(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $section = Section::factory()->create(['class_id' => $c['source']->class_id, 'name' => 'B']);
        $data = $this->payload($c, ['target_class_id' => $c['source']->class_id, 'target_section_id' => $section->id]);
        $data['rows'][0]['action'] = 'REPEAT';
        $batch = $this->process($u, $data);
        $this->assertDatabaseHas('promotion_outcomes', ['promotion_batch_id' => $batch->id, 'action' => 'REPEAT']);
        $this->assertDatabaseHas('student_enrollments', ['academic_year_id' => $c['targetYear']->id, 'class_id' => $c['source']->class_id, 'section_id' => $section->id]);
    }

    public function test_withdrawal_and_transfer_record_terminal_history_without_new_placement(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 2);
        $data = $this->exitPayload($c);
        $data['rows'][1]['action'] = 'TRANSFER';
        $this->process($u, $data);
        $this->assertDatabaseCount('student_enrollments', 2);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['records'][0]->id, 'status' => 'WITHDRAWN']);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['records'][1]->id, 'status' => 'TRANSFERRED']);
        $this->assertDatabaseCount('promotion_outcomes', 2);
        $this->actingAs($u)->post('/enrollments/'.$c['records'][0]->id.'/activate')->assertForbidden();
        $this->get('/enrollments/'.$c['records'][1]->id.'/edit')->assertForbidden();
        $this->get('/enrollments/'.$c['records'][0]->id)->assertOk()->assertSee('WITHDRAWN');
        $this->assertDatabaseHas('students', ['id' => $c['records'][0]->student_id, 'status' => 'ACTIVE']);
    }

    public function test_closed_source_can_be_promoted_without_mutation_but_cannot_exit(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 2);
        $c['sourceYear']->status = 'CLOSED';
        $c['sourceYear']->save();
        $data = $this->payload($c);
        $data['rows'] = [$data['rows'][0]];
        $this->process($u, $data);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['records'][0]->id, 'status' => 'ACTIVE']);
        $data = $this->exitPayload($c);
        $data['rows'] = [$data['rows'][1]];
        $this->expectException(ValidationException::class);
        $this->process($u, $data);
    }

    public function test_target_capacity_failure_rolls_back_entire_batch(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 2);
        $c['target']->capacity = 1;
        $c['target']->save();
        try {
            $this->process($u, $this->payload($c));
            $this->fail('Expected capacity failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }$this->assertDatabaseCount('promotion_batches', 0);
        $this->assertDatabaseCount('promotion_outcomes', 0);
        $this->assertDatabaseCount('student_enrollments', 2);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ENROLLMENT_CREATED', 'entity_id' => $c['records'][1]->id + 1]);
    }

    public function test_duplicate_new_rolls_roll_back_entire_batch(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 2);
        $data = $this->payload($c);
        $data['rows'][1]['roll_number'] = $data['rows'][0]['roll_number'];
        try {
            $this->process($u, $data);
            $this->fail('Expected duplicate roll.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }$this->assertDatabaseCount('promotion_batches', 0);
        $this->assertDatabaseCount('student_enrollments', 2);
    }

    public function test_audit_failure_rolls_back_outcomes_and_target_enrollments(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit failed'));
        try {
            $this->process($u, $this->payload($c));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }$this->assertDatabaseCount('promotion_batches', 0);
        $this->assertDatabaseCount('promotion_outcomes', 0);
        $this->assertDatabaseCount('student_enrollments', 1);
    }

    public function test_wrong_decision_missing_confirmation_and_bad_exit_data_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $data = $this->payload($c);
        $this->actingAs($u)->post('/promotions', [...$data, 'confirmed' => '0'])->assertSessionHasErrors('confirmed');
        $data['rows'][0]['action'] = 'REPEAT';
        $this->post('/promotions', $data)->assertSessionHasErrors();
        $data['rows'][0]['action'] = 'PROMOTE';
        $data['rows'][0]['roll_number'] = null;
        $this->post('/promotions', $data)->assertSessionHasErrors();
        $exit = $this->exitPayload($c);
        $exit['rows'][0]['notes'] = '';
        $this->post('/promotions', $exit)->assertSessionHasErrors();
        $exit = $this->exitPayload($c);
        $exit['rows'][0]['action'] = 'PROMOTE';
        $this->post('/promotions', $exit)->assertSessionHasErrors();
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_non_future_draft_and_archived_years_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $data = $this->payload($c);
        $this->actingAs($u)->post('/promotions', [...$data, 'target_academic_year_id' => $c['sourceYear']->id])->assertSessionHasErrors('target_academic_year_id');
        foreach (['DRAFT', 'ARCHIVED'] as $status) {
            DB::table('academic_years')->where('id', $c['sourceYear']->id)->update(['status' => $status]);
            $this->post('/promotions', $data)->assertSessionHasErrors('source_academic_year_id');
        }DB::table('academic_years')->where('id', $c['sourceYear']->id)->update(['status' => 'ACTIVE']);
        DB::table('academic_years')->where('id', $c['targetYear']->id)->update(['status' => 'DRAFT']);
        $this->post('/promotions', $data)->assertSessionHasErrors('target_academic_year_id');
    }

    public function test_inactive_student_and_target_parents_are_rechecked(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $data = $this->payload($c);
        $this->actingAs($u);
        DB::table('students')->where('id', $c['records'][0]->student_id)->update(['status' => 'INACTIVE']);
        $this->post('/promotions', $data)->assertSessionHasErrors();
        DB::table('students')->where('id', $c['records'][0]->student_id)->update(['status' => 'ACTIVE']);
        DB::table('sections')->where('id', $c['target']->id)->update(['status' => 'INACTIVE']);
        $this->post('/promotions', $data)->assertNotFound();
        DB::table('sections')->where('id', $c['target']->id)->update(['status' => 'ACTIVE']);
        DB::table('classes')->where('id', $c['target']->class_id)->update(['status' => 'INACTIVE']);
        $this->post('/promotions', $data)->assertNotFound();
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_existing_later_enrollment_prevents_outdated_promotion_or_exit(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        app(EnrollmentService::class)->save($u, null, ['student_id' => $c['records'][0]->student_id, 'academic_year_id' => $c['targetYear']->id, 'class_id' => $c['target']->class_id, 'section_id' => $c['target']->id, 'roll_number' => 1, 'joined_at' => '2027-04-01']);
        $this->actingAs($u)->post('/promotions', $this->payload($c))->assertSessionHasErrors();
        $this->post('/promotions', $this->exitPayload($c))->assertSessionHasErrors();
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_wizard_single_shortcut_and_selected_rows_only(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 2);
        $data = $this->payload($c);
        $params = ['mode' => 'PLACEMENT'];
        $this->actingAs($u);
        foreach (['source_academic_year_id', 'source_class_id', 'source_section_id', 'target_academic_year_id', 'target_class_id', 'target_section_id'] as $field) {
            $this->get('/promotions/create?'.http_build_query($params))->assertOk()->assertSee('Select');
            $params[$field] = $data[$field];
        }
        $this->get('/promotions/create?'.http_build_query($params))->assertOk()->assertSee('Confirm selected students');
        $this->get('/promotions/create?source_enrollment_id='.$c['records'][0]->id.'&mode=EXIT')->assertOk()->assertSee('Withdraw or transfer students');
        $data['from_selection'] = '1';
        $data['rows'][0]['selected'] = '1';
        $this->post('/promotions', $data)->assertRedirect();
        $this->assertDatabaseCount('promotion_outcomes', 1);
        $this->assertDatabaseCount('student_enrollments', 3);
    }

    public function test_read_only_and_guest_users_cannot_process_promotions(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $reader = User::factory()->create(['school_id' => $u->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($u, $role, [DB::table('permissions')->where('name', 'student.view')->value('id')]);
        app(AccessManager::class)->assignRoles($u, $reader, [$role->id]);
        $this->actingAs($reader)->get('/promotions')->assertOk()->assertDontSee('Promote / repeat');
        $this->get('/promotions/create')->assertForbidden();
        $this->post('/promotions', $this->payload($c))->assertForbidden();
        $this->post('/logout');
        $this->get('/promotions')->assertRedirect('/login');
        $this->post('/promotions', $this->payload($c))->assertRedirect('/login');
    }

    public function test_cross_school_ids_are_rejected_by_routes_and_services(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $other = $this->admin('OTHER');
        $foreign = $this->setupContext($other);
        $foreignBatch = $this->process($other, $this->payload($foreign));
        $this->actingAs($u)->get('/promotions/'.$foreignBatch->id)->assertNotFound();
        $this->get('/promotions/create?source_enrollment_id='.$foreign['records'][0]->id)->assertNotFound();
        $data = $this->payload($c);
        $data['rows'][0]['source_enrollment_id'] = $foreign['records'][0]->id;
        $this->post('/promotions', $data)->assertNotFound();
        $this->assertDatabaseCount('promotion_batches', 1);
        app(CurrentSchool::class)->set($u->school);
        $this->expectException(AuthorizationException::class);
        app(PromotionService::class)->process($other, $this->payload($foreign));
    }

    public function test_mismatched_source_and_target_sections_fail_atomically(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $data = $this->payload($c);
        $this->actingAs($u)->post('/promotions', [...$data, 'source_class_id' => $c['target']->class_id])->assertNotFound();
        $this->post('/promotions', [...$data, 'target_section_id' => $c['source']->id])->assertNotFound();
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_history_search_and_pagination(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 23);
        $batch = $this->process($u, $this->payload($c));
        $admission = $c['records'][0]->student->admission_number;
        $this->actingAs($u)->get('/promotions?q='.urlencode($admission))->assertOk()->assertSee($admission)->assertViewHas('outcomes', fn ($rows) => $rows->total() === 1);
        $this->get('/promotions/'.$batch->id.'?q=PROMOTE')->assertOk()->assertViewHas('outcomes', fn ($rows) => $rows->total() === 23 && $rows->count() === 20 && str_contains($rows->url(2), 'q=PROMOTE'));
    }

    public function test_stale_enrollment_instance_cannot_reactivate_terminal_exit(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $stale = $c['records'][0];
        $this->process($u, $this->exitPayload($c));
        $this->expectException(AuthorizationException::class);
        app(EnrollmentService::class)->setStatus($u, $stale, EnrollmentStatus::Active);
    }

    public function test_database_rejects_second_outcome_for_source(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $batch = $this->process($u, $this->exitPayload($c));
        $this->expectException(QueryException::class);
        DB::table('promotion_outcomes')->insert(['school_id' => $u->school_id, 'promotion_batch_id' => $batch->id, 'student_id' => $c['records'][0]->student_id, 'source_enrollment_id' => $c['records'][0]->id, 'action' => 'WITHDRAW']);
    }

    public function test_mixed_promotion_and_exit_batch_is_atomic(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 2);
        $data = $this->payload($c);
        $data['rows'][1]['action'] = 'TRANSFER';
        $data['rows'][1]['roll_number'] = null;
        $data['rows'][1]['notes'] = 'Moved to another school';
        $this->process($u, $data);
        $this->assertDatabaseCount('student_enrollments', 3);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['records'][1]->id, 'status' => 'TRANSFERRED']);
        $this->assertDatabaseCount('promotion_outcomes', 2);
    }

    public function test_unselected_empty_duplicate_or_out_of_scope_rows_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $data = $this->payload($c);
        $this->actingAs($u)->post('/promotions', [...$data, 'from_selection' => '1'])->assertSessionHasErrors('rows');
        $data['rows'][] = $data['rows'][0];
        $this->post('/promotions', $data)->assertSessionHasErrors();
        $data = $this->payload($c);
        $this->post('/promotions', [...$data, 'school_id' => 999, 'actor_id' => 999])->assertSessionHasErrors(['school_id', 'actor_id']);
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_exit_audit_failure_restores_source_status(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit failed'));
        try {
            $this->process($u, $this->exitPayload($c));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['records'][0]->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('promotion_batches', 0);
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_joined_date_validation_rolls_back_batch(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $this->actingAs($u)->post('/promotions', $this->payload($c, ['joined_at' => '2026-04-01']))->assertSessionHasErrors();
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_database_rejects_outcome_linked_to_another_student(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u, 2);
        $data = $this->exitPayload($c);
        $data['rows'] = [$data['rows'][0]];
        $batch = $this->process($u, $data);
        $this->expectException(QueryException::class);
        DB::table('promotion_outcomes')->insert(['school_id' => $u->school_id, 'promotion_batch_id' => $batch->id, 'student_id' => $c['records'][0]->student_id, 'source_enrollment_id' => $c['records'][1]->id, 'action' => 'TRANSFER']);
    }

    public function test_database_rejects_cross_school_batch_reference(): void
    {
        $u = $this->admin();
        $c = $this->setupContext($u);
        $other = $this->admin('OTHER');
        $foreign = $this->setupContext($other);
        $batch = $this->process($other, $this->exitPayload($foreign));
        $this->expectException(QueryException::class);
        DB::table('promotion_outcomes')->insert(['school_id' => $u->school_id, 'promotion_batch_id' => $batch->id, 'student_id' => $c['records'][0]->student_id, 'source_enrollment_id' => $c['records'][0]->id, 'action' => 'WITHDRAW']);
    }
}

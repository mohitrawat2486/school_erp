<?php

namespace Tests\Feature;

use App\Enums\GradingSchemeStatus;
use App\Models\GradingScheme;
use App\Models\ResultBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GradingSchemeService;
use App\Services\MarksVerificationService;
use App\Services\ProvisionSchool;
use App\Services\ResultApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarksContext;
use Tests\Concerns\BuildsResultContext;
use Tests\TestCase;

class ResultApprovalTest extends TestCase
{
    use BuildsMarksContext,BuildsResultContext,RefreshDatabase;

    private function batch(array $c, GradingScheme $s): ResultBatch
    {
        return app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
    }

    private function url(ResultBatch $b): string
    {
        return '/results/'.$b->id;
    }

    private function action(ResultBatch $b, string $action, array $extra = [])
    {
        return $this->post($this->url($b), array_replace(['action' => $action, 'version' => $b->fresh()->lock_version], $extra));
    }

    public function test_generate_review_approve_publish_and_saved_student_details(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $this->actingAs($c['admin'])->get('/results/create')->assertOk();
        $this->get('/results/create?exam_id='.$c['exam']->id)->assertOk();
        $this->get('/results/create?exam_id='.$c['exam']->id.'&grading_scheme_id='.$s->id)->assertOk()->assertSee('Confirm generation');
        $this->post('/results', ['exam_id' => $c['exam']->id, 'grading_scheme_id' => $s->id])->assertSessionHasNoErrors();
        $b = ResultBatch::first();
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'VERIFICATION']);
        $this->get($this->url($b))->assertOk()->assertSee('85.00');
        $draft = $b->drafts()->first();
        $this->get($this->url($b).'/students/'.$draft->id)->assertOk()->assertSee('Achieved');
        $this->action($b, 'approve')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('result_batches', ['id' => $b->id, 'status' => 'APPROVED', 'approved_by' => $c['admin']->id, 'lock_version' => 2]);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'APPROVED']);
        $this->action($b, 'publish')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('result_batches', ['id' => $b->id, 'status' => 'PUBLISHED', 'published_by' => $c['admin']->id, 'lock_version' => 3]);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'PUBLISHED']);
        $this->assertNotNull($b->fresh()->published_at);
        foreach (['RESULT_GENERATED', 'RESULT_APPROVED', 'RESULT_PUBLISHED'] as $a) {
            $this->assertDatabaseHas('audit_logs', ['action' => $a]);
        }
        $this->get($this->url($b))->assertOk()->assertDontSee('Confirm publish')->assertDontSee('Confirm return');
    }

    public function test_missing_or_unlocked_marks_prevent_generation_atomically(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->actingAs($c['admin'])->post('/results', ['exam_id' => $c['exam']->id, 'grading_scheme_id' => $s->id])->assertSessionHasErrors();
        $m = $this->locked($c);
        app(MarksVerificationService::class)->transition($c['admin'], $m, ['action' => 'unlock', 'version' => 3, 'reason' => 'Correction']);
        $this->post('/results', ['exam_id' => $c['exam']->id, 'grading_scheme_id' => $s->id])->assertSessionHasErrors();
        $this->assertDatabaseCount('result_batches', 0);
        $this->assertDatabaseCount('result_drafts', 0);
    }

    public function test_duplicate_generation_and_publish_before_approval_fail(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        $this->post('/results', ['exam_id' => $c['exam']->id, 'grading_scheme_id' => $s->id])->assertSessionHasErrors();
        $this->action($b, 'publish')->assertSessionHasErrors();
        $this->assertDatabaseCount('result_batches', 1);
    }

    public function test_return_for_correction_preserves_old_revision_and_permits_regeneration(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $m = $this->locked($c);
        $b = $this->batch($c, $s);
        $old = $b->drafts()->first()->payload;
        $this->actingAs($c['admin']);
        $this->action($b, 'return', ['reason' => 'Correct marks'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'MARKS_ENTRY']);
        $this->assertDatabaseHas('result_batches', ['id' => $b->id, 'status' => 'RETURNED', 'return_reason' => 'Correct marks']);
        app(MarksVerificationService::class)->transition($c['admin'], $m, ['action' => 'unlock', 'version' => 3, 'reason' => 'Correct marks']);
        $this->locked($c, ['version' => 4, 'theory_marks' => 55]);
        $next = $this->batch($c, $s);
        $this->assertSame(2, $next->revision);
        $this->assertSame('85.00', $old['obtained_marks']);
        $this->assertSame('80.00', $next->drafts()->first()->payload['obtained_marks']);
        $this->assertSame($old, $b->drafts()->first()->payload);
    }

    public function test_regeneration_creates_revision_two_and_retains_old_payload(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        $this->action($b, 'return', ['reason' => 'Review again'])->assertSessionHasNoErrors();
        $next = $this->batch($c, $s);
        $this->assertSame(2, $next->revision);
        $this->assertSame('RETURNED', $b->fresh()->status);
        $this->assertSame($b->drafts()->first()->payload, $next->drafts()->first()->payload);
        $this->assertDatabaseCount('result_batches', 2);
    }

    public function test_independent_generation_approval_publication_permissions(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $generator = $this->user($c, ['result.view', 'result.generate']);
        $approver = $this->user($c, ['result.view', 'result.approve']);
        $publisher = $this->user($c, ['result.view', 'result.publish']);
        $this->actingAs($generator)->post('/results', ['exam_id' => $c['exam']->id, 'grading_scheme_id' => $s->id])->assertSessionHasNoErrors();
        $b = ResultBatch::first();
        $this->action($b, 'approve')->assertForbidden();
        $this->actingAs($approver);
        $this->action($b, 'approve')->assertSessionHasNoErrors();
        $this->action($b, 'publish')->assertForbidden();
        $this->actingAs($publisher);
        $this->action($b, 'publish')->assertSessionHasNoErrors();
    }

    public function test_viewer_and_marks_manager_cannot_generate_or_approve(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        foreach ([['result.view'], ['marks.view', 'marks.lock', 'result.view']] as $p) {
            $u = $this->user($c, $p);
            $this->actingAs($u)->get('/results')->assertOk();
            $this->get($this->url($b))->assertOk()->assertDontSee('Confirm approve');
            $this->get('/results/create')->assertForbidden();
            $this->action($b, 'approve')->assertForbidden();
        }
    }

    public function test_source_changes_after_generation_block_approval_and_after_approval_block_publication(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $m = $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        DB::table('student_marks')->where('id', $m->id)->increment('lock_version');
        $this->action($b, 'approve')->assertSessionHasErrors('source');
        DB::table('student_marks')->where('id', $m->id)->decrement('lock_version');
        $this->action($b, 'approve')->assertSessionHasNoErrors();
        $this->enroll($c, 2);
        $this->action($b, 'publish')->assertSessionHasErrors('source');
        $this->assertDatabaseHas('result_batches', ['status' => 'APPROVED']);
    }

    public function test_configuration_and_roster_identity_changes_require_regeneration(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        $student = $c['enrollment']->student;
        $name = $student->first_name;
        $student->first_name = 'Changed';
        $student->save();
        $this->action($b, 'approve')->assertSessionHasErrors('source');
        $student->first_name = $name;
        $student->save();
        app(GradingSchemeService::class)->setStatus($c['admin'], $s, GradingSchemeStatus::Inactive, ['version' => 4]);
        $this->action($b, 'approve')->assertSessionHasErrors('source');
    }

    public function test_payload_tampering_and_row_deletion_prevent_publication(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        $draft = $b->drafts()->first();
        $original = $draft->payload;
        $bad = $original;
        $bad['grade'] = 'Forged';
        DB::table('result_drafts')->where('id', $draft->id)->update(['payload' => json_encode($bad)]);
        $this->action($b, 'approve')->assertSessionHasErrors('source');
        DB::table('result_drafts')->where('id', $draft->id)->update(['payload' => json_encode($original)]);
        $this->action($b, 'approve')->assertSessionHasNoErrors();
        DB::table('result_drafts')->where('id', $draft->id)->delete();
        $this->action($b, 'publish')->assertSessionHasErrors('source');
    }

    public function test_stale_versions_and_duplicate_actions_are_rejected(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        $this->action($b, 'approve')->assertSessionHasNoErrors();
        $this->action($b, 'publish', ['version' => 1])->assertSessionHasErrors('version');
        $this->action($b, 'approve')->assertSessionHasErrors();
        $this->action($b, 'publish')->assertSessionHasNoErrors();
        $this->action($b, 'publish')->assertSessionHasErrors();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'RESULT_PUBLISHED')->count());
    }

    public function test_closed_year_and_exam_state_mismatch_block_transition(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        $c['year']->status = 'CLOSED';
        $c['year']->save();
        $this->action($b, 'approve')->assertSessionHasErrors();
        $c['year']->status = 'ACTIVE';
        $c['year']->save();
        $c['exam']->status = 'CLOSED';
        $c['exam']->save();
        $this->action($b, 'approve')->assertSessionHasErrors();
    }

    public function test_return_requires_reason_and_published_batch_cannot_be_returned(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        foreach ([null, '', '  ', str_repeat('x', 1001)] as $reason) {
            $this->action($b, 'return', ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->action($b, 'approve');
        $this->action($b, 'publish');
        $this->action($b, 'return', ['reason' => 'Too late'])->assertSessionHasErrors();
    }

    public function test_marks_are_frozen_during_review_and_reopened_only_by_return(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $m = $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin'])->post('/examinations/marks-review/'.$m->id, ['action' => 'unlock', 'version' => 3, 'reason' => 'Correction'])->assertSessionHasErrors();
        $this->action($b, 'return', ['reason' => 'Correction']);
        $this->post('/examinations/marks-review/'.$m->id, ['action' => 'unlock', 'version' => 3, 'reason' => 'Correction'])->assertSessionHasNoErrors();
    }

    public function test_generation_audit_failure_rolls_back_batch_rows_and_exam(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            $this->batch($c, $s);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('result_batches', 0);
        $this->assertDatabaseCount('result_drafts', 0);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'MARKS_ENTRY']);
    }

    public function test_publication_audit_failure_rolls_back_status_and_metadata(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseHas('result_batches', ['id' => $b->id, 'status' => 'APPROVED', 'published_by' => null, 'lock_version' => 2]);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'APPROVED']);
    }

    public function test_published_payload_is_displayed_after_live_grading_and_names_change(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        $this->action($b, 'approve');
        $this->action($b, 'publish');
        $draft = $b->drafts()->first();
        $original = $draft->payload;
        $student = $c['enrollment']->student;
        $student->first_name = 'New Name';
        $student->save();
        app(GradingSchemeService::class)->setStatus($c['admin'], $s, GradingSchemeStatus::Inactive, ['version' => 4]);
        $this->get($this->url($b).'/students/'.$draft->id)->assertOk()->assertSee($original['student_name'])->assertSee('Achieved')->assertDontSee('New Name');
        $this->assertSame($original, $draft->fresh()->payload);
    }

    public function test_school_isolation_nested_rows_and_guests(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->get('/results')->assertRedirect('/login');
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other', $other->email);
        $other = $other->fresh();
        $this->actingAs($other)->get($this->url($b))->assertNotFound();
        $this->post($this->url($b), ['action' => 'approve', 'version' => 1])->assertNotFound();
        $this->get('/results')->assertOk()->assertViewHas('batches', fn ($r) => $r->total() === 0);
    }

    public function test_result_rows_and_published_batches_reject_model_mutation(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $draft = $b->drafts()->first();
        try {
            $draft->forceFill(['payload' => []])->save();
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('result', $e->errors());
        }app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);
        $b = $b->fresh();
        try {
            $b->forceFill(['return_reason' => 'Change'])->save();
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch', $e->errors());
        }
    }

    public function test_generation_picker_search_and_batch_status_filter(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $this->actingAs($c['admin'])->get('/results/create?q=NONEXISTENT')->assertOk()->assertViewHas('records', fn ($r) => $r->total() === 0);
        $b = $this->batch($c, $s);
        $this->get('/results?status=GENERATED')->assertOk()->assertViewHas('batches', fn ($r) => $r->total() === 1);
        $this->get('/results?q=NONEXISTENT')->assertOk()->assertViewHas('batches', fn ($r) => $r->total() === 0);
        $this->get('/results?status=INVALID')->assertSessionHasErrors();
    }

    public function test_student_and_batch_history_pagination_and_nested_ownership(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $first = null;
        for ($i = 0; $i < 22; $i++) {
            $b = $this->batch($c, $s);
            $first ??= $b;
            app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'return', 'version' => 1, 'reason' => 'History revision']);
        }
        for ($i = 2; $i <= 23; $i++) {
            $e = $this->enroll($c, $i);
            $this->locked($c, ['enrollment_id' => $e->id]);
        }
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin'])->get('/results')->assertOk()->assertViewHas('batches', fn ($r) => $r->total() === 23 && $r->count() === 20);
        $this->get('/results?page=2')->assertOk()->assertViewHas('batches', fn ($r) => $r->count() === 3);
        $this->get($this->url($b))->assertOk()->assertViewHas('drafts', fn ($r) => $r->total() === 23 && $r->count() === 20);
        $this->get($this->url($b).'?page=2')->assertOk()->assertViewHas('drafts', fn ($r) => $r->count() === 3);
        $this->get($this->url($b).'/students/'.$first->drafts()->first()->id)->assertNotFound();
    }

    public function test_draft_identity_mismatch_blocks_approval_even_with_unchanged_payload(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $other = $this->enroll($c, 2);
        $other->status = 'INACTIVE';
        $other->save();
        $d = $b->drafts()->first();
        DB::table('result_drafts')->where('id', $d->id)->update(['student_id' => $other->student_id, 'enrollment_id' => $other->id]);
        $this->actingAs($c['admin']);
        $this->action($b, 'approve')->assertSessionHasErrors('source');
    }

    public function test_database_prevents_duplicate_current_batch_invalid_status_and_actor(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $base = $b->fresh()->getAttributes();
        unset($base['id'],$base['active_exam_id']);
        $base['revision'] = 2;
        try {
            DB::table('result_batches')->insert($base);
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }
        foreach ([['status' => 'INVALID'], ['status' => 'PUBLISHED'], ['generated_by' => null], ['approved_by' => 99999999]] as $bad) {
            try {
                DB::table('result_batches')->where('id', $b->id)->update($bad);
                $this->fail();
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[0], ['23000', 'HY000']);
            }
        }
    }

    public function test_return_audit_failure_keeps_marks_frozen_and_actor_permissions_are_fresh(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $fresh = $c['admin']->fresh();
        $fresh->is_active = false;
        $fresh->save();
        try {
            app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
            $this->fail();
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }
        $fresh->is_active = true;
        $fresh->save();
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'return', 'version' => 1, 'reason' => 'Correction']);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseHas('result_batches', ['id' => $b->id, 'status' => 'GENERATED', 'lock_version' => 1, 'returned_by' => null]);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'VERIFICATION']);
    }

    public function test_published_exam_can_close_and_year_can_close_without_changing_published_payload(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = $this->batch($c, $s);
        $this->actingAs($c['admin']);
        $this->action($b, 'approve');
        $this->action($b, 'publish');
        $payload = $b->drafts()->first()->payload;
        $this->post('/examinations/exams/'.$c['exam']->id.'/close')->assertSessionHasNoErrors();
        $this->post('/academics/academic-years/'.$c['year']->id.'/close')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_years', ['id' => $c['year']->id, 'status' => 'CLOSED']);
        $this->assertSame('PUBLISHED', $b->fresh()->status);
        $this->assertSame($payload, $b->drafts()->first()->payload);
        $this->get($this->url($b))->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\StudentMark;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarksEntryService;
use App\Services\MarksVerificationService;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class MarksVerificationTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function submitted(array $c): StudentMark
    {
        $data = $this->payload($c);
        $data['action'] = 'submit';
        app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $data);

        return StudentMark::first();
    }

    private function reviewUrl(StudentMark $mark): string
    {
        return '/examinations/marks-review/'.$mark->id;
    }

    private function action(StudentMark $mark, string $action, array $extra = [])
    {
        return $this->post($this->reviewUrl($mark), array_replace(['action' => $action, 'version' => $mark->fresh()->lock_version], $extra));
    }

    public function test_submitted_verified_locked_and_unlock_resubmission_cycle(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        $this->get('/examinations/marks-review')->assertOk()->assertSee('Marks review queue');
        $this->get($this->reviewUrl($mark))->assertOk()->assertSee('Verify submitted marks');
        $this->action($mark, 'verify')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['id' => $mark->id, 'status' => 'VERIFIED', 'verified_by' => $c['admin']->id, 'lock_version' => 2]);
        $this->assertNotNull($mark->fresh()->verified_at);
        $this->action($mark, 'lock')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['id' => $mark->id, 'status' => 'LOCKED', 'locked_by' => $c['admin']->id, 'lock_version' => 3]);
        $this->assertNotNull($mark->fresh()->locked_at);
        $this->action($mark, 'unlock', ['reason' => 'Correct a transcription error'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['id' => $mark->id, 'status' => 'DRAFT', 'submitted_by' => null, 'submitted_at' => null, 'verified_by' => null, 'verified_at' => null, 'locked_by' => null, 'locked_at' => null, 'total_marks' => '85.00', 'lock_version' => 4]);
        $audit = DB::table('audit_logs')->where('action', 'MARKS_UNLOCKED')->first();
        $this->assertSame('Correct a transcription error', json_decode($audit->new_values, true)['unlock_reason']);
        $this->assertSame('LOCKED', json_decode($audit->old_values, true)['status']);
        $data = $this->payload($c, ['version' => 4, 'theory_marks' => 55]);
        $data['action'] = 'submit';
        $this->post($this->url($c), $data)->assertSessionHasNoErrors();
        $this->action($mark, 'verify')->assertSessionHasNoErrors();
        $this->action($mark, 'lock')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['total_marks' => '80.00', 'status' => 'LOCKED']);
    }

    public function test_locked_marks_cannot_be_changed_through_entry_or_review(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify');
        $this->action($mark, 'lock');
        $this->post($this->url($c), $this->payload($c, ['version' => 3, 'theory_marks' => 1]))->assertSessionHasErrors();
        $this->action($mark, 'verify')->assertSessionHasErrors();
        $this->assertDatabaseHas('student_marks', ['status' => 'LOCKED', 'theory_marks' => '60.00']);
    }

    public function test_unlock_requires_specific_permission_even_for_verifier_or_locker(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify');
        $this->action($mark, 'lock');
        $user = $this->user($c, ['marks.view', 'marks.verify', 'marks.lock']);
        $this->actingAs($user)->get($this->reviewUrl($mark))->assertOk()->assertDontSee('Unlock for correction');
        $this->action($mark, 'unlock', ['reason' => 'Correction'])->assertForbidden();
        $this->assertDatabaseHas('student_marks', ['status' => 'LOCKED']);
    }

    public function test_unlock_only_user_has_review_access_but_no_other_actions_or_entry(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify');
        $this->action($mark, 'lock');
        $user = $this->user($c, ['marks.view', 'marks.unlock']);
        $this->actingAs($user)->get('/examinations/marks-review')->assertOk();
        $this->get($this->reviewUrl($mark))->assertOk()->assertSee('Unlock for correction');
        $this->action($mark, 'unlock', ['reason' => 'Authorized correction'])->assertSessionHasNoErrors();
        $this->action($mark, 'verify')->assertForbidden();
        $this->post($this->url($c), $this->payload($c, ['version' => 4]))->assertForbidden();
    }

    public function test_permission_to_verify_does_not_grant_lock(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $user = $this->user($c, ['marks.view', 'marks.verify']);
        $this->actingAs($user);
        $this->action($mark, 'verify')->assertSessionHasNoErrors();
        $this->action($mark, 'lock')->assertForbidden();
    }

    public function test_permission_to_lock_does_not_grant_verification(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $user = $this->user($c, ['marks.view', 'marks.lock']);
        $this->actingAs($user);
        $this->action($mark, 'verify')->assertForbidden();
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify');
        $this->actingAs($user);
        $this->action($mark, 'lock')->assertSessionHasNoErrors();
    }

    public function test_teacher_and_plain_viewer_cannot_access_review_or_mutate_workflow(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        foreach ([$this->teacher($c), $this->user($c, ['marks.view'])] as $user) {
            $this->actingAs($user)->get('/examinations/marks-review')->assertForbidden();
            $this->get($this->reviewUrl($mark))->assertForbidden();
            $this->action($mark, 'verify')->assertForbidden();
        }
    }

    public function test_unlock_reason_is_required_nonblank_and_bounded(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify');
        $this->action($mark, 'lock');
        foreach ([null, '', '   ', str_repeat('a', 1001)] as $reason) {
            $this->action($mark, 'unlock', ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->assertDatabaseHas('student_marks', ['status' => 'LOCKED']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'MARKS_UNLOCKED']);
    }

    public function test_wrong_order_and_repeated_actions_are_rejected(): void
    {
        $c = $this->context();
        app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $this->payload($c));
        $mark = StudentMark::first();
        $this->actingAs($c['admin']);
        foreach (['verify', 'lock', 'unlock'] as $a) {
            $this->action($mark, $a, ['reason' => 'Correction'])->assertSessionHasErrors();
        }
        $data = $this->payload($c, ['version' => 1]);
        $data['action'] = 'submit';
        $this->post($this->url($c), $data);
        $this->action($mark, 'lock')->assertSessionHasErrors();
        $this->action($mark, 'verify')->assertSessionHasNoErrors();
        $this->action($mark, 'verify')->assertSessionHasErrors();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'MARKS_VERIFIED')->count());
    }

    public function test_versions_block_stale_actions_and_replayed_unlock(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify');
        $this->action($mark, 'lock', ['version' => 1])->assertSessionHasErrors('version');
        $this->action($mark, 'lock');
        $this->action($mark, 'unlock', ['version' => 3, 'reason' => 'Correction'])->assertSessionHasNoErrors();
        $this->action($mark, 'unlock', ['version' => 3, 'reason' => 'Again'])->assertSessionHasErrors('version');
    }

    public function test_closed_year_and_later_exam_states_freeze_all_actions(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        foreach (['DRAFT', 'OPEN', 'VERIFICATION', 'APPROVED', 'PUBLISHED', 'CLOSED'] as $status) {
            $c['exam']->status = $status;
            $c['exam']->save();
            $this->action($mark, 'verify')->assertSessionHasErrors();
            $this->get($this->reviewUrl($mark))->assertOk()->assertDontSee('Confirm verify');
        }
        $c['exam']->status = 'MARKS_ENTRY';
        $c['exam']->save();
        $c['year']->status = 'CLOSED';
        $c['year']->save();
        $this->action($mark, 'verify')->assertSessionHasErrors();
    }

    public function test_inactive_roster_context_blocks_verification_but_keeps_history(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        foreach ([$c['subject'], $c['allocation'], $c['section'], $c['enrollment'], $c['enrollment']->student] as $model) {
            $model->status = 'INACTIVE';
            $model->save();
            $this->action($mark, 'verify')->assertSessionHasErrors();
            $this->get($this->reviewUrl($mark))->assertOk();
            $model->status = 'ACTIVE';
            $model->save();
        }
    }

    public function test_audit_failure_rolls_back_workflow_and_version(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(MarksVerificationService::class)->transition($c['admin'], $mark, ['action' => 'verify', 'version' => 1]);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseHas('student_marks', ['status' => 'SUBMITTED', 'verified_by' => null, 'lock_version' => 1]);
    }

    public function test_queue_filters_pagination_and_escape_remarks(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $mark->remarks = '<script>alert(1)</script>';
        $mark->save();
        $this->actingAs($c['admin'])->get($this->reviewUrl($mark))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false);
        for ($i = 2; $i <= 23; $i++) {
            $e = $this->enroll($c, $i);
            $data = $this->payload($c, ['enrollment_id' => $e->id]);
            $data['action'] = 'submit';
            app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $data);
        }
        $this->get('/examinations/marks-review?status=SUBMITTED')->assertOk()->assertViewHas('marks', fn ($r) => $r->total() === 23 && $r->count() === 20);
        $this->get('/examinations/marks-review?page=2')->assertOk()->assertViewHas('marks', fn ($r) => $r->count() === 3);
        $this->get('/examinations/marks-review?q=NONEXISTENT')->assertOk()->assertViewHas('marks', fn ($r) => $r->total() === 0);
        $this->get('/examinations/marks-review?status=INVALID')->assertSessionHasErrors();
    }

    public function test_school_isolation_guests_and_direct_service_ownership(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->get('/examinations/marks-review')->assertRedirect('/login');
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other School', $other->email);
        $other = $other->fresh();
        app(CurrentSchool::class)->set($other->school);
        try {
            app(MarksVerificationService::class)->transition($other, $mark, ['action' => 'verify', 'version' => 1]);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->actingAs($other)->get($this->reviewUrl($mark))->assertNotFound();
        $this->post($this->reviewUrl($mark), ['action' => 'verify', 'version' => 1])->assertNotFound();
        $this->get('/examinations/marks-review')->assertOk()->assertViewHas('marks', fn ($r) => $r->total() === 0);
    }

    public function test_payload_cannot_change_totals_or_actors(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        foreach ([['status' => 'LOCKED'], ['verified_by' => 999], ['locked_by' => 999], ['total_marks' => 1]] as $bad) {
            $this->action($mark, 'verify', $bad)->assertSessionHasErrors();
        }$this->assertDatabaseHas('student_marks', ['status' => 'SUBMITTED', 'total_marks' => '85.00']);
    }

    public function test_service_rechecks_revoked_permissions_and_stale_status(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $actor = $this->user($c, ['marks.view', 'marks.verify']);
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify');
        try {
            app(MarksVerificationService::class)->transition($actor, $mark, ['action' => 'verify', 'version' => 1]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }
        $fresh = $actor->fresh();
        $fresh->is_active = false;
        $fresh->save();
        try {
            app(MarksVerificationService::class)->transition($actor, $mark, ['action' => 'verify', 'version' => 2]);
            $this->fail();
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_absent_rows_can_be_verified_and_locked_without_numeric_marks(): void
    {
        $c = $this->context();
        $data = $this->payload($c, ['theory_marks' => null, 'practical_marks' => null, 'is_absent' => true]);
        $data['action'] = 'submit';
        app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $data);
        $mark = StudentMark::first();
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify')->assertSessionHasNoErrors();
        $this->action($mark, 'lock')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['status' => 'LOCKED', 'is_absent' => true, 'total_marks' => null]);
    }

    public function test_review_revalidates_component_limits_and_verification_metadata(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $c['subject']->theory_max_marks = 50;
        $c['subject']->save();
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify')->assertSessionHasErrors();
        $c['subject']->theory_max_marks = 70;
        $c['subject']->save();
        $this->action($mark, 'verify')->assertSessionHasNoErrors();
        DB::table('student_marks')->where('id', $mark->id)->update(['verified_by' => null, 'verified_at' => null]);
        $this->action($mark, 'lock')->assertSessionHasErrors('marks');
        $this->assertDatabaseHas('student_marks', ['status' => 'VERIFIED']);
    }

    public function test_unlock_is_atomic_with_audit_and_blocked_after_publication(): void
    {
        $c = $this->context();
        $mark = $this->submitted($c);
        $this->actingAs($c['admin']);
        $this->action($mark, 'verify');
        $this->action($mark, 'lock');
        $c['exam']->status = 'PUBLISHED';
        $c['exam']->save();
        $this->action($mark, 'unlock', ['reason' => 'Correction'])->assertSessionHasErrors();
        $c['exam']->status = 'MARKS_ENTRY';
        $c['exam']->save();
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(MarksVerificationService::class)->transition($c['admin'], $mark, ['action' => 'unlock', 'version' => 3, 'reason' => 'Correction']);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseHas('student_marks', ['status' => 'LOCKED', 'locked_by' => $c['admin']->id, 'lock_version' => 3]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'MARKS_UNLOCKED']);
    }
}

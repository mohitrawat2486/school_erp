<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarksEntryService;
use App\Services\MarksVerificationService;
use App\Services\ProvisionSchool;
use App\Services\ResultApprovalService;
use App\Services\UserAdministrationService;
use App\Support\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarksContext;
use Tests\Concerns\BuildsResultContext;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use BuildsMarksContext, BuildsResultContext, RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null, 'name' => 'Principal at action']);
        app(ProvisionSchool::class)->run('AUDIT', 'Audit School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    public function test_audit_list_and_detail_require_permission_and_exclude_other_schools(): void
    {
        $admin = $this->admin();
        $log = app(AuditLogger::class)->record($admin, 'LOCAL_CHANGE', 'student', 123);
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        $hidden = app(AuditLogger::class)->record($foreign, 'FOREIGN_SECRET_ACTION', 'student', 456);
        $this->get('/administration/audit-logs')->assertRedirect('/login');
        $this->actingAs($foreign)->get('/administration/audit-logs')->assertForbidden();
        $this->get('/administration/audit-logs/'.$hidden->id)->assertForbidden();
        $this->actingAs($admin)->get('/administration/audit-logs')->assertOk()->assertSee('LOCAL_CHANGE')->assertDontSee('FOREIGN_SECRET_ACTION');
        $this->get('/administration/audit-logs/'.$hidden->id)->assertNotFound();
        $this->get('/administration/audit-logs/'.$log->id)->assertOk()->assertSee('Principal at action');
        $c = ['admin' => $admin];
        $reader = $this->user($c, ['audit.view']);
        $this->actingAs($reader)->get('/administration/audit-logs/'.$log->id)->assertOk();
        $this->put('/administration/audit-logs/'.$log->id, ['action' => 'FORGED'])->assertMethodNotAllowed();
        $this->delete('/administration/audit-logs/'.$log->id)->assertMethodNotAllowed();
    }

    public function test_filters_combine_and_pagination_preserves_query(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 27; $i++) {
            $log = app(AuditLogger::class)->record($admin, 'MARKS_DRAFT_SAVED', 'student_mark_filter_fixture', 99, [], ['sample' => $i], 'examinations');
        }
        app(AuditLogger::class)->record($admin, 'OTHER_ACTION', 'student', 88);
        $query = ['module' => 'examinations', 'action' => 'MARKS_DRAFT_SAVED', 'user_id' => $admin->id, 'entity_type' => 'student_mark_filter_fixture', 'entity_id' => 99, 'from' => now()->toDateString(), 'to' => now()->toDateString()];
        $url = '/administration/audit-logs?'.http_build_query($query);
        $this->actingAs($admin)->get($url)->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 27 && $logs->first()->id === $log->id)->assertSee('page=2', false)->assertViewHas('logs', fn ($logs) => $logs->every(fn ($entry) => $entry->action === 'MARKS_DRAFT_SAVED'));
        $this->get($url.'&page=2')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->count() === 2 && $logs->total() === 27);
        $this->get('/administration/audit-logs?entity_id=987654')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 0);
    }

    public function test_dates_include_the_whole_end_day_and_validate_ranges(): void
    {
        $admin = $this->admin();
        foreach (['2026-10-01 00:00:00', '2026-10-01 23:59:59', '2026-10-02 00:00:00'] as $time) {
            AuditLog::create(['user_id' => $admin->id, 'module' => 'dates', 'action' => 'DATE_EVENT', 'entity_type' => 'sample', 'entity_id' => 1, 'created_at' => $time]);
        }
        $this->actingAs($admin)->get('/administration/audit-logs?module=dates&from=2026-10-01&to=2026-10-01')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 2);
        $this->get('/administration/audit-logs?module=dates&to=2026-10-01')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 2);
        $this->getJson('/administration/audit-logs?from=2026-10-02&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/administration/audit-logs?from=bad&user_id=-1')->assertUnprocessable()->assertJsonValidationErrors(['from', 'user_id']);
    }

    public function test_detail_escapes_values_distinguishes_missing_and_null_and_keeps_actor_snapshot(): void
    {
        $admin = $this->admin();
        $log = app(AuditLogger::class)->record($admin, 'MARK_UPDATED', 'sample', 1, ['marks' => 74, 'unchanged' => 1, 'removed' => null], ['marks' => 84, 'unchanged' => 1, 'added' => null, 'remarks' => '<script>alert(1)</script>']);
        $admin->update(['name' => 'Renamed later']);
        $this->actingAs($admin)->get('/administration/audit-logs/'.$log->id)->assertOk()->assertSee('Principal at action')->assertSee('74')->assertSee('84')->assertSee('Not present')->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)->assertViewHas('changes', fn ($changes) => ! isset($changes['unchanged']) && $changes['removed']['old_present'] && ! $changes['removed']['new_present'] && $changes['added']['new_present']);
    }

    public function test_http_audit_captures_request_metadata_and_redacts_nested_secrets(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '192.0.2.42'])->withHeader('User-Agent', 'Audit test browser')->post('/administration/roles', ['name' => 'AUDIT_REVIEW', 'permissions' => []])->assertRedirect();
        $log = AuditLog::where('action', 'ROLE_CREATED')->firstOrFail();
        $this->assertSame('192.0.2.42', $log->ip_address);
        $this->assertSame('Audit test browser', $log->user_agent);
        $log = app(AuditLogger::class)->record($admin, 'SECRET_TEST', 'sample', 1, ['password' => 'hidden'], ['nested' => [['api_token' => 'hidden', 'secret_key' => 'hidden', 'safe' => 74]]]);
        $this->assertSame('[REDACTED]', $log->old_values['password']);
        $this->assertSame('[REDACTED]', $log->new_values['nested'][0]['api_token']);
        $this->assertSame(74, $log->new_values['nested'][0]['safe']);
    }

    public function test_account_enable_disable_events_are_explicit_and_transactional(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        $data = ['name' => $member->name, 'email' => $member->email, 'roles' => [], 'is_active' => false];
        app(UserAdministrationService::class)->save($admin, $member, $data);
        $disabled = AuditLog::where('action', 'USER_DISABLED')->firstOrFail();
        $this->assertTrue($disabled->old_values['is_active']);
        $this->assertFalse($disabled->new_values['is_active']);
        app(UserAdministrationService::class)->save($admin, $member, array_replace($data, ['is_active' => true]));
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_ENABLED', 'entity_id' => $member->id]);
        app(UserAdministrationService::class)->save($admin, $member, array_replace($data, ['is_active' => true]));
        $this->assertSame(1, AuditLog::where('action', 'USER_ENABLED')->count());
    }

    public function test_marks_changes_unlock_and_publication_keep_complete_audit_history(): void
    {
        $c = $this->context();
        $scheme = $this->scheme($c);
        $mark = $this->locked($c);
        app(MarksVerificationService::class)->transition($c['admin'], $mark, ['action' => 'unlock', 'version' => $mark->lock_version, 'reason' => 'Correct transcription']);
        $unlock = AuditLog::where('action', 'MARKS_UNLOCKED')->firstOrFail();
        $this->assertSame('LOCKED', $unlock->old_values['status']);
        $this->assertSame('DRAFT', $unlock->new_values['status']);
        $this->assertSame('Correct transcription', $unlock->new_values['unlock_reason']);
        app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $this->payload($c, ['version' => $mark->fresh()->lock_version, 'theory_marks' => 64]));
        $change = AuditLog::where('action', 'MARKS_DRAFT_SAVED')->latest('id')->firstOrFail();
        $this->assertEquals(60, $change->old_values['theory_marks']);
        $this->assertEquals(64, $change->new_values['theory_marks']);
        $name = $c['enrollment']->student->fullName();
        $this->assertSame($name, $change->context['student_name']);
        $this->assertSame($c['subject']->subject->name, $change->context['subject_name']);
        $c['enrollment']->student->update(['first_name' => 'Renamed']);
        $this->assertSame($name, $change->fresh()->context['student_name']);
        $this->locked($c, ['version' => $mark->fresh()->lock_version, 'theory_marks' => 64]);
        $service = app(ResultApprovalService::class);
        $batch = $service->generate($c['admin'], $c['exam'], $scheme);
        $service->transition($c['admin'], $batch, ['action' => 'approve', 'version' => 1]);
        $service->transition($c['admin'], $batch, ['action' => 'publish', 'version' => 2]);
        foreach (['RESULT_GENERATED', 'RESULT_APPROVED', 'RESULT_PUBLISHED', 'RESULT_SNAPSHOTS_ARCHIVED', 'ENROLLMENT_CREATED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'school_id' => $c['admin']->school_id, 'user_id' => $c['admin']->id]);
        }
    }

    public function test_model_and_query_builder_cannot_change_or_remove_history(): void
    {
        $admin = $this->admin();
        $log = app(AuditLogger::class)->record($admin, 'PRESERVED', 'sample', 1);
        foreach ([fn () => $log->update(['action' => 'FORGED']), fn () => $log->delete(), fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'FORGED']), fn () => DB::table('audit_logs')->where('id', $log->id)->delete()] as $mutate) {
            try {
                $mutate();
                $this->fail('Audit mutation was accepted.');
            } catch (\LogicException|QueryException $exception) {
                $this->assertStringContainsString('Audit records', $exception->getMessage());
            }
        }
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'PRESERVED']);
    }

    public function test_audit_failure_rolls_back_the_accompanying_account_change(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(UserAdministrationService::class)->save($admin, $member, ['name' => 'Must roll back', 'email' => $member->email, 'roles' => [], 'is_active' => false]);
            $this->fail('Audit failure should propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertTrue($member->fresh()->is_active);
        $this->assertNotSame('Must roll back', $member->fresh()->name);
    }

    public function test_legacy_entries_do_not_invent_names_or_request_metadata(): void
    {
        $admin = $this->admin();
        $log = AuditLog::create(['user_id' => $admin->id, 'module' => 'administration', 'action' => 'LEGACY_ACTION', 'entity_type' => 'user', 'entity_id' => $admin->id, 'old_values' => null, 'new_values' => ['is_active' => true], 'created_at' => now()]);
        $this->assertNull($log->context);
        $this->assertNull($log->ip_address);
        $this->actingAs($admin)->get('/administration/audit-logs/'.$log->id)->assertOk()->assertSee('Name not recorded')->assertSee('Not recorded')->assertViewHas('changes', fn ($changes) => ! $changes['is_active']['old_present'] && $changes['is_active']['new_present']);
    }
}

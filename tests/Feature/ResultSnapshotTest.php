<?php

namespace Tests\Feature;

use App\Enums\GradingSchemeStatus;
use App\Models\ResultBatch;
use App\Models\StudentResult;
use App\Models\StudentResultSubject;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GradingSchemeService;
use App\Services\ProvisionSchool;
use App\Services\ResultApprovalService;
use App\Services\ResultCalculationService;
use App\Services\ResultSnapshotService;
use App\Support\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsMarksContext;
use Tests\Concerns\BuildsResultContext;
use Tests\TestCase;

class ResultSnapshotTest extends TestCase
{
    use BuildsMarksContext,BuildsResultContext,RefreshDatabase;

    private function published(array $c, bool $legacy = false): ResultBatch
    {
        $s = $this->scheme($c);
        $this->locked($c);
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        if ($legacy) {
            DB::table('result_batches')->where('id', $b->id)->update(['status' => 'PUBLISHED', 'published_by' => $c['admin']->id, 'published_at' => now()]);
            DB::table('exams')->where('id', $c['exam']->id)->update(['status' => 'PUBLISHED']);
        } else {
            app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);
        }

        return $b->fresh();
    }

    public function test_publication_creates_normalized_snapshots_and_seals_batch(): void
    {
        $c = $this->context();
        $b = $this->published($c);
        $r = StudentResult::first();
        $s = $r->subjects()->first();
        $this->assertNotNull($b->snapshots_completed_at);
        $this->assertSame('85.00', $r->obtained_marks);
        $this->assertSame('Achieved', $r->grade);
        $this->assertSame('4.00', $r->grade_point);
        $this->assertSame(1, $r->version);
        $this->assertSame(1, $r->rank);
        $this->assertTrue($r->published_at->equalTo($b->published_at));
        $this->assertSame('60.00', $s->theory_marks);
        $this->assertSame('25.00', $s->practical_marks);
        $this->assertSame($c['subject']->subject->name, $s->subject_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RESULT_SNAPSHOTS_ARCHIVED']);
        $this->assertArrayNotHasKey('updated_at', $r->getAttributes());
    }

    public function test_archive_is_idempotent_without_duplicate_rows_or_audits(): void
    {
        $c = $this->context();
        $b = $this->published($c);
        $count = DB::table('audit_logs')->count();
        $this->assertSame(0, app(ResultSnapshotService::class)->archive($c['admin'], $b));
        $this->assertDatabaseCount('student_results', 1);
        $this->assertDatabaseCount('student_result_subjects', 1);
        $this->assertSame($count, DB::table('audit_logs')->count());
    }

    public function test_published_reads_use_snapshots_and_survive_live_identity_and_grade_changes(): void
    {
        $c = $this->context();
        $b = $this->published($c);
        $r = StudentResult::first();
        $original = $r->summary();
        $student = $c['enrollment']->student;
        $student->first_name = 'Changed student';
        $student->save();
        $subject = $c['subject']->subject;
        $subject->name = 'Changed subject';
        $subject->save();
        $c['section']->name = 'Changed section';
        $c['section']->save();
        $scheme = $b->gradingScheme;
        app(GradingSchemeService::class)->setStatus($c['admin'], $scheme, GradingSchemeStatus::Inactive, ['version' => 4]);
        $this->mock(ResultCalculationService::class)->shouldNotReceive('calculateExam');
        $this->actingAs($c['admin'])->get('/published-results/'.$r->id)->assertOk()->assertSee($original['student_name'])->assertSee('Achieved')->assertDontSee('Changed student')->assertDontSee('Changed subject');
        $this->get('/results/'.$b->id)->assertOk()->assertViewHas('drafts', fn ($rows) => $rows->first() instanceof StudentResult);
        $this->get('/results/'.$b->id.'/students/'.$r->source_draft_id)->assertOk()->assertViewIs('results.snapshot');
    }

    public function test_snapshot_reads_remain_available_after_exam_and_year_closure(): void
    {
        $c = $this->context();
        $b = $this->published($c);
        $this->actingAs($c['admin'])->post('/examinations/exams/'.$c['exam']->id.'/close')->assertSessionHasNoErrors();
        $this->post('/academics/academic-years/'.$c['year']->id.'/close')->assertSessionHasNoErrors();
        $this->get('/published-results/'.StudentResult::first()->id)->assertOk()->assertSee('Achieved');
    }

    public function test_model_updates_and_deletes_are_rejected(): void
    {
        $c = $this->context();
        $this->published($c);
        foreach ([StudentResult::first(), StudentResultSubject::first()] as $model) {
            try {
                $model->forceFill(['grade' => 'Forged'])->save();
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('snapshot', $e->errors());
            }try {
                $model->delete();
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('snapshot', $e->errors());
            }
        }
    }

    public function test_database_bulk_update_delete_and_append_are_blocked_after_sealing(): void
    {
        $c = $this->context();
        $b = $this->published($c);
        foreach (['student_results', 'student_result_subjects'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    if ($action === 'update') {
                        DB::table($table)->update(['grade' => 'Forged']);
                    } else {
                        DB::table($table)->delete();
                    }
                    $this->fail();
                } catch (QueryException $e) {
                    $this->assertSame('45000', $e->errorInfo[0]);
                }
            }
            $row = DB::table($table)->first();
            $copy = (array) $row;
            unset($copy['id']);
            try {
                DB::table($table)->insert($copy);
                $this->fail();
            } catch (QueryException $e) {
                $this->assertSame('45000', $e->errorInfo[0]);
            }
        }try {
            DB::table('result_batches')->where('id', $b->id)->update(['snapshots_completed_at' => null]);
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
        }
    }

    public function test_original_published_payload_is_sealed_against_sql_changes(): void
    {
        $c = $this->context();
        $b = $this->published($c);
        foreach (['update', 'delete', 'insert'] as $action) {
            try {
                if ($action === 'update') {
                    DB::table('result_drafts')->where('result_batch_id', $b->id)->update(['payload' => '{}']);
                } elseif ($action === 'delete') {
                    DB::table('result_drafts')->where('result_batch_id', $b->id)->delete();
                } else {
                    $row = (array) DB::table('result_drafts')->where('result_batch_id', $b->id)->first();
                    unset($row['id']);
                    DB::table('result_drafts')->insert($row);
                }
                $this->fail();
            } catch (QueryException $e) {
                $this->assertSame('45000', $e->errorInfo[0]);
            }
        }
    }

    public function test_failed_snapshot_creation_rolls_back_publication_and_all_snapshots(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Archive audit failed'));
        try {
            app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Archive audit failed', $e->getMessage());
        }
        $this->assertDatabaseCount('student_results', 0);
        $this->assertDatabaseCount('student_result_subjects', 0);
        $this->assertDatabaseHas('result_batches', ['id' => $b->id, 'status' => 'APPROVED', 'snapshots_completed_at' => null, 'lock_version' => 2]);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'APPROVED']);
    }

    public function test_legacy_backfill_uses_saved_payload_without_live_calculation(): void
    {
        $c = $this->context();
        $b = $this->published($c, true);
        $old = $b->drafts()->first()->payload;
        $student = $c['enrollment']->student;
        $student->first_name = 'Changed';
        $student->save();
        $c['year']->status = 'CLOSED';
        $c['year']->save();
        app(GradingSchemeService::class)->setStatus($c['admin'], $b->gradingScheme, GradingSchemeStatus::Inactive, ['version' => 4]);
        $this->mock(ResultCalculationService::class)->shouldNotReceive('calculateExam');
        $this->assertSame(1, app(ResultSnapshotService::class)->archive($c['admin'], $b));
        $r = StudentResult::first();
        $this->assertSame($old['student_name'], $r->student_name);
        $this->assertSame($old['grade'], $r->grade);
        $this->assertTrue($r->published_at->equalTo($b->published_at));
    }

    public function test_backfill_command_is_scoped_authorized_and_repeatable(): void
    {
        $c = $this->context();
        $b = $this->published($c, true);
        $this->artisan('results:archive-published', ['school' => $c['admin']->school->code, 'email' => $c['admin']->email, '--batch' => $b->id])->assertExitCode(0);
        $this->artisan('results:archive-published', ['school' => $c['admin']->school->code, 'email' => $c['admin']->email, '--batch' => $b->id])->assertExitCode(0);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->assertSame(1, StudentResult::count());
        $viewer = $this->user($c, ['result.view']);
        $this->artisan('results:archive-published', ['school' => $c['admin']->school->code, 'email' => $viewer->email])->assertExitCode(1);
        app(CurrentSchool::class)->set($c['admin']->school);
    }

    public function test_nonpublished_batch_cannot_be_archived(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        $this->expectException(ValidationException::class);
        app(ResultSnapshotService::class)->archive($c['admin'], $b);
    }

    public function test_archive_requires_publish_permission_but_read_requires_only_result_view(): void
    {
        $c = $this->context();
        $b = $this->published($c);
        $viewer = $this->user($c, ['result.view']);
        $this->actingAs($viewer)->get('/published-results/'.StudentResult::first()->id)->assertOk();
        try {
            app(ResultSnapshotService::class)->archive($viewer, $b);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $none = $this->user($c, []);
        $this->actingAs($none)->get('/published-results/'.StudentResult::first()->id)->assertForbidden();
    }

    public function test_school_isolation_guests_and_direct_archive_checks(): void
    {
        $c = $this->context();
        $b = $this->published($c);
        $r = StudentResult::first();
        $this->get('/published-results/'.$r->id)->assertRedirect('/login');
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other', $other->email);
        $other = $other->fresh();
        app(CurrentSchool::class)->set($other->school);
        try {
            app(ResultSnapshotService::class)->archive($other, $b);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->actingAs($other)->get('/published-results/'.$r->id)->assertNotFound();
    }

    public function test_tampered_legacy_payload_is_not_backfilled(): void
    {
        $c = $this->context();
        $b = $this->published($c, true);
        DB::table('result_drafts')->where('result_batch_id', $b->id)->update(['payload' => '{}']);
        $this->expectException(ValidationException::class);
        app(ResultSnapshotService::class)->archive($c['admin'], $b);
    }

    public function test_unarchived_legacy_batch_never_falls_back_to_live_or_draft_payload_reads(): void
    {
        $c = $this->context();
        $b = $this->published($c, true);
        $this->actingAs($c['admin'])->get('/results/'.$b->id)->assertStatus(409);
        $this->get('/results/'.$b->id.'/students/'.$b->drafts()->first()->id)->assertStatus(409);
    }

    public function test_absent_subject_and_failed_result_snapshot_preserve_status_and_null_components(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c, ['is_absent' => true, 'theory_marks' => null, 'practical_marks' => null]);
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);
        $r = StudentResult::first();
        $subject = $r->subjects()->first();
        $this->assertSame('FAIL', $r->result_status);
        $this->assertNull($r->rank);
        $this->assertSame('ABSENT', $subject->result_status);
        $this->assertTrue($subject->is_absent);
        $this->assertNull($subject->theory_marks);
        $this->assertSame('0.00', $subject->obtained_marks);
    }

    public function test_returned_revisions_are_not_archived_and_publication_version_matches_revision(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $old = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        app(ResultApprovalService::class)->transition($c['admin'], $old, ['action' => 'return', 'version' => 1, 'reason' => 'Revision']);
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);
        $this->assertSame(2, StudentResult::first()->version);
        $this->assertSame(0, $old->studentResults()->count());
    }

    public function test_published_snapshot_roster_pagination_uses_normalized_rows(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        for ($i = 2; $i <= 23; $i++) {
            $e = $this->enroll($c, $i);
            $this->locked($c, ['enrollment_id' => $e->id]);
        }
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);
        $this->assertSame(23, StudentResult::count());
        $this->assertSame(23, StudentResultSubject::count());
        $this->actingAs($c['admin'])->get('/results/'.$b->id)->assertOk()->assertViewHas('drafts', fn ($r) => $r->count() === 20 && $r->first() instanceof StudentResult);
        $this->get('/results/'.$b->id.'?page=2')->assertOk()->assertViewHas('drafts', fn ($r) => $r->count() === 3);
    }

    public function test_backfill_audit_failure_rolls_back_archive_and_can_be_retried(): void
    {
        $c = $this->context();
        $b = $this->published($c, true);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('No archive audit'));
        try {
            app(ResultSnapshotService::class)->archive($c['admin'], $b);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('No archive audit', $e->getMessage());
        }
        $this->assertSame(0, StudentResult::count());
        $this->assertSame(0, StudentResultSubject::count());
        $this->assertNull($b->fresh()->snapshots_completed_at);
        $this->app->forgetInstance(AuditLogger::class);
        $this->assertSame(1, app(ResultSnapshotService::class)->archive($c['admin'], $b));
    }

    public function test_backfill_command_rejects_other_school_batch_and_invalid_identifiers(): void
    {
        $c = $this->context();
        $b = $this->published($c, true);
        $this->artisan('results:archive-published', ['school' => $c['admin']->school->code, 'email' => $c['admin']->email, '--batch' => 'bad'])->assertExitCode(1);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->artisan('results:archive-published', ['school' => 'UNKNOWN', 'email' => $c['admin']->email])->assertExitCode(1);
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other', $other->email);
        $other = $other->fresh();
        $this->artisan('results:archive-published', ['school' => 'OTHER', 'email' => $other->email, '--batch' => $b->id])->assertExitCode(1);
    }
}

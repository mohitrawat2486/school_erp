<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use App\Services\MarksEntryService;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class StudentProgressionTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(int $count = 3, string $code = 'PROGRESSION'): array
    {
        $admin = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run($code, 'Progression School', $admin->email);
        $admin = $admin->fresh();
        app(CurrentSchool::class)->set($admin->school);
        $year = AcademicYear::create(['name' => '2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        $year->forceFill(['status' => 'ACTIVE', 'is_current' => true])->save();
        $next = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $next->forceFill(['status' => 'ACTIVE'])->save();
        $section = Section::factory()->create(['capacity' => null]);
        $class = $section->schoolClass;
        $target = Section::factory()->create(['capacity' => null]);
        $repeat = Section::factory()->create(['class_id' => $class->id, 'name' => 'Repeat section', 'capacity' => null]);
        $c = compact('admin', 'year', 'next', 'section', 'class', 'target', 'repeat');
        $records = [];
        foreach (range(1, $count) as $roll) {
            $records[] = $this->enroll($c, $roll);
        }

        return $c + ['records' => $records, 'enrollment' => $records[0]];
    }

    private function command(array $c): array
    {
        return ['operation_key' => (string) Str::uuid(), 'source_academic_year_id' => $c['year']->id, 'source_class_id' => $c['class']->id, 'source_section_id' => $c['section']->id, 'target_academic_year_id' => $c['next']->id, 'target_class_id' => $c['target']->class_id, 'target_section_id' => $c['target']->id, 'joined_at' => '2027-04-01', 'rows' => array_map(fn ($record) => ['source_enrollment_id' => $record->id, 'action' => 'PROMOTE', 'roll_number' => null], $c['records'])];
    }

    private function sourceUrl(array $c, array $extra = []): string
    {
        return '/students/progression/roster?'.http_build_query(['source_academic_year_id' => $c['year']->id, 'source_class_id' => $c['class']->id, 'source_section_id' => $c['section']->id] + $extra);
    }

    private function review(array $c, ?array $command = null): array
    {
        return $this->actingAs($c['admin'])->postJson('/students/progression/review', $command ?? $this->command($c))->assertOk()->json();
    }

    private function confirm(array $review)
    {
        return $this->postJson('/students/progression', ['review_token' => $review['review_token'], 'confirmed' => true]);
    }

    public function test_students_list_contains_workflow_and_no_promotion_sidebar_entry(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/students')->assertOk()->assertSee('data-progression-dialog', false)->assertSee('Move to next academic year')->assertDontSee('label="Promotion"', false);
        $reader = $this->user($c, ['student.view']);
        $this->actingAs($reader)->get('/students')->assertOk()->assertDontSee('data-progression-dialog', false)->assertDontSee('Move to next academic year');
        $this->getJson($this->sourceUrl($c))->assertForbidden();
        $this->postJson('/students/progression/review', $this->command($c))->assertForbidden();
        $this->postJson('/students/progression', [])->assertForbidden();
    }

    public function test_roster_search_pagination_and_recorded_outcomes_are_clear(): void
    {
        $c = $this->workspace(23);
        $this->actingAs($c['admin'])->getJson($this->sourceUrl($c))->assertOk()->assertJsonCount(20, 'rows')->assertJsonPath('total', 23)->assertJsonPath('eligible_count', 23)->assertJsonPath('last_page', 2)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->sourceUrl($c, ['page' => 2]))->assertOk()->assertJsonCount(3, 'rows');
        $admission = $c['records'][0]->student->admission_number;
        $this->getJson($this->sourceUrl($c, ['q' => $admission]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('rows.0.admission_number', $admission);
        $command = $this->command($c);
        $command['rows'] = [$command['rows'][0]];
        $this->confirm($this->review($c, $command))->assertOk();
        $this->getJson($this->sourceUrl($c))->assertOk()->assertJsonPath('rows.0.eligible', false)->assertJsonPath('eligible_count', 22)->assertSee('Promote');
    }

    public function test_review_is_read_only_and_mixed_promotion_repeat_is_atomic_with_history(): void
    {
        $c = $this->workspace();
        $command = $this->command($c);
        $command['rows'][1]['action'] = 'REPEAT';
        $command['rows'][1]['target_section_id'] = $c['repeat']->id;
        $command['rows'] = array_slice($command['rows'], 0, 2);
        $before = $c['records'][0]->fresh()->getAttributes();
        $review = $this->review($c, $command);
        $this->assertSame(['promoted' => 1, 'repeating' => 1, 'left_for_later' => 1], $review['summary']);
        $this->assertDatabaseCount('student_enrollments', 3);
        $this->assertDatabaseCount('promotion_batches', 0);
        $this->confirm($review)->assertOk()->assertJsonPath('promoted', 1)->assertJsonPath('repeating', 1);
        $this->assertDatabaseCount('student_enrollments', 5);
        $this->assertDatabaseCount('promotion_batches', 1);
        $this->assertDatabaseCount('promotion_outcomes', 2);
        $this->assertSame($before, $c['records'][0]->fresh()->getAttributes());
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $c['records'][1]->student_id, 'academic_year_id' => $c['next']->id, 'class_id' => $c['class']->id, 'section_id' => $c['repeat']->id]);
        $this->get('/students/'.$c['records'][0]->student_id)->assertOk()->assertSee('Promoted')->assertSee('Promoted from')->assertSee('2027-28');
        $this->get('/students/'.$c['records'][1]->student_id)->assertOk()->assertSee('Repeating')->assertSee('Repeated from');
    }

    public function test_exact_confirmation_retry_does_not_duplicate_outcomes_or_audits(): void
    {
        $c = $this->workspace();
        $review = $this->review($c);
        $first = $this->confirm($review)->assertOk()->json('batch_id');
        $auditCount = DB::table('audit_logs')->count();
        $this->confirm($review)->assertOk()->assertJsonPath('batch_id', $first);
        $this->assertDatabaseCount('promotion_batches', 1);
        $this->assertDatabaseCount('promotion_outcomes', 3);
        $this->assertDatabaseCount('student_enrollments', 6);
        $this->assertDatabaseCount('audit_logs', $auditCount);
    }

    public function test_same_key_with_different_review_is_rejected(): void
    {
        $c = $this->workspace();
        $command = $this->command($c);
        $first = $this->review($c, $command);
        $command['rows'] = [$command['rows'][0]];
        $second = $this->review($c, $command);
        $this->confirm($first)->assertOk();
        $this->confirm($second)->assertUnprocessable()->assertJsonValidationErrors('operation_key');
        $this->assertDatabaseCount('promotion_outcomes', 3);
    }

    public function test_auto_rolls_reserve_manual_numbers_first_and_keep_inactive_reservations(): void
    {
        $c = $this->workspace(2);
        $other = Student::factory()->create(['admission_date' => '2026-04-01']);
        $existing = app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => $other->id, 'academic_year_id' => $c['next']->id, 'class_id' => $c['target']->class_id, 'section_id' => $c['target']->id, 'roll_number' => 5, 'joined_at' => '2027-04-01']);
        app(EnrollmentService::class)->setStatus($c['admin'], $existing, EnrollmentStatus::Inactive);
        $command = $this->command($c);
        $command['rows'][1]['roll_number'] = 6;
        $review = $this->review($c, $command);
        $this->assertSame(7, $review['rows'][0]['roll_number']);
        $this->assertSame(6, $review['rows'][1]['roll_number']);
        $this->confirm($review)->assertOk();
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $c['records'][0]->student_id, 'academic_year_id' => $c['next']->id, 'roll_number' => 7]);
    }

    public function test_capacity_and_duplicate_manual_rolls_fail_before_writes(): void
    {
        $c = $this->workspace(2);
        $c['target']->update(['capacity' => 1]);
        $this->actingAs($c['admin'])->postJson('/students/progression/review', $this->command($c))->assertUnprocessable()->assertJsonValidationErrors('rows');
        $c['target']->update(['capacity' => 3]);
        $command = $this->command($c);
        $command['rows'][0]['roll_number'] = 10;
        $command['rows'][1]['roll_number'] = 10;
        $this->postJson('/students/progression/review', $command)->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->assertDatabaseCount('promotion_batches', 0);
        $this->assertDatabaseCount('student_enrollments', 2);
    }

    public function test_review_must_be_refreshed_after_destination_or_student_changes(): void
    {
        $c = $this->workspace();
        $review = $this->review($c);
        $c['records'][0]->student->update(['first_name' => 'Changed since review']);
        $this->confirm($review)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $review = $this->review($c);
        $c['target']->update(['capacity' => 50]);
        $this->confirm($review)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_roll_or_capacity_reservation_after_review_blocks_stale_confirmation(): void
    {
        $c = $this->workspace();
        $review = $this->review($c);
        app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => Student::factory()->create(['admission_date' => '2026-04-01'])->id, 'academic_year_id' => $c['next']->id, 'class_id' => $c['target']->class_id, 'section_id' => $c['target']->id, 'roll_number' => 1, 'joined_at' => '2027-04-01']);
        $this->confirm($review)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_tampered_expired_and_other_actor_reviews_are_rejected(): void
    {
        $c = $this->workspace();
        $review = $this->review($c);
        $this->postJson('/students/progression', ['review_token' => 'tampered', 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $other = $this->user($c, ['student.view', 'student.update']);
        $this->actingAs($other);
        $this->confirm($review)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->actingAs($c['admin']);
        $this->travel(31)->minutes();
        $this->confirm($review)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->travelBack();
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_closed_sources_and_repeat_only_batches_work(): void
    {
        $c = $this->workspace(2);
        $c['year']->forceFill(['status' => 'CLOSED', 'is_current' => false])->save();
        $command = $this->command($c);
        $command['target_class_id'] = $c['class']->id;
        $command['target_section_id'] = $c['repeat']->id;
        foreach ($command['rows'] as &$row) {
            $row['action'] = 'REPEAT';
            $row['target_section_id'] = $c['repeat']->id;
        }
        unset($row);
        $review = $this->review($c, $command);
        $this->confirm($review)->assertOk()->assertJsonPath('repeating', 2)->assertJsonPath('promoted', 0);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['records'][0]->id, 'status' => 'ACTIVE']);
    }

    public function test_archived_sources_and_nonlater_or_inactive_targets_are_rejected(): void
    {
        $c = $this->workspace();
        $command = $this->command($c);
        $this->actingAs($c['admin'])->postJson('/students/progression/review', [...$command, 'target_academic_year_id' => $c['year']->id])->assertUnprocessable()->assertJsonValidationErrors('target_academic_year_id');
        $c['next']->forceFill(['status' => 'DRAFT'])->save();
        $this->postJson('/students/progression/review', $command)->assertNotFound();
        $c['year']->forceFill(['status' => 'ARCHIVED', 'is_current' => false])->save();
        $this->getJson($this->sourceUrl($c))->assertNotFound();
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_decisions_inactive_students_and_later_enrollments_are_rechecked(): void
    {
        $c = $this->workspace();
        $command = $this->command($c);
        $command['rows'][0] += ['target_section_id' => $c['repeat']->id];
        $this->actingAs($c['admin'])->postJson('/students/progression/review', $command)->assertUnprocessable()->assertJsonValidationErrors('rows');
        $command = $this->command($c);
        $c['records'][0]->student->forceFill(['status' => 'INACTIVE'])->save();
        $this->postJson('/students/progression/review', $command)->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->getJson($this->sourceUrl($c))->assertJsonPath('rows.0.eligible', false);
        $c['records'][0]->student->forceFill(['status' => 'ACTIVE'])->save();
        app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => $c['records'][0]->student_id, 'academic_year_id' => $c['next']->id, 'class_id' => $c['target']->class_id, 'section_id' => $c['target']->id, 'roll_number' => 1, 'joined_at' => '2027-04-01']);
        $this->postJson('/students/progression/review', $command)->assertUnprocessable()->assertJsonValidationErrors('rows');
    }

    public function test_changed_repeat_section_and_full_repeat_destination_are_validated(): void
    {
        $c = $this->workspace(2);
        $command = $this->command($c);
        $command['rows'][1]['action'] = 'REPEAT';
        $command['rows'][1]['target_section_id'] = $c['repeat']->id;
        $c['repeat']->update(['capacity' => 1]);
        app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => Student::factory()->create(['admission_date' => '2026-04-01'])->id, 'academic_year_id' => $c['next']->id, 'class_id' => $c['class']->id, 'section_id' => $c['repeat']->id, 'roll_number' => 99, 'joined_at' => '2027-04-01']);
        $this->actingAs($c['admin'])->postJson('/students/progression/review', $command)->assertUnprocessable()->assertJsonValidationErrors('rows');
        $c['repeat']->update(['capacity' => null]);
        $review = $this->review($c, $command);
        $c['repeat']->forceFill(['status' => 'INACTIVE'])->save();
        $this->confirm($review)->assertNotFound();
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_invalid_dates_row_shapes_confirmation_and_limits_are_rejected(): void
    {
        $c = $this->workspace();
        $command = $this->command($c);
        $this->actingAs($c['admin'])->postJson('/students/progression/review', [...$command, 'joined_at' => '2026-04-01'])->assertUnprocessable()->assertJsonValidationErrors('joined_at');
        foreach ([[], [$command['rows'][0], $command['rows'][0]], array_fill(0, 101, $command['rows'][0]), [['source_enrollment_id' => $c['records'][0]->id, 'action' => 'WITHDRAW']], [['source_enrollment_id' => $c['records'][0]->id, 'action' => 'REPEAT']]] as $rows) {
            $this->postJson('/students/progression/review', [...$command, 'rows' => $rows])->assertUnprocessable();
        }
        $this->postJson('/students/progression/review', [...$command, 'school_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('school_id');
        $review = $this->review($c);
        $this->postJson('/students/progression', ['review_token' => $review['review_token'], 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_audit_failure_rolls_back_mixed_batch_and_retry_can_complete(): void
    {
        $c = $this->workspace(2);
        $command = $this->command($c);
        $command['rows'][1]['action'] = 'REPEAT';
        $command['rows'][1]['target_section_id'] = $c['repeat']->id;
        $review = $this->review($c, $command);
        $original = app(AuditLogger::class);
        $calls = 0;
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($original, &$calls) {
            if (++$calls === 3) {
                throw new \RuntimeException('Audit failed');
            }

            return $original->record(...$args);
        });
        $this->confirm($review)->assertServerError();
        $this->assertDatabaseCount('promotion_batches', 0);
        $this->assertDatabaseCount('promotion_outcomes', 0);
        $this->assertDatabaseCount('student_enrollments', 2);
        app()->instance(AuditLogger::class, $original);
        $this->confirm($review)->assertOk();
    }

    public function test_unselected_students_can_be_processed_in_a_later_batch(): void
    {
        $c = $this->workspace(2);
        $command = $this->command($c);
        $command['rows'] = [$command['rows'][0]];
        $this->confirm($this->review($c, $command))->assertOk();
        $command = $this->command($c);
        $command['rows'] = [$command['rows'][1]];
        $review = $this->review($c, $command);
        $this->assertSame(0, $review['summary']['left_for_later']);
        $this->assertSame(2, $review['rows'][0]['roll_number']);
        $this->confirm($review)->assertOk();
        $this->assertDatabaseCount('promotion_batches', 2);
        $this->assertDatabaseCount('promotion_outcomes', 2);
    }

    public function test_foreign_source_destination_and_review_token_cannot_cross_schools(): void
    {
        $c = $this->workspace();
        $review = $this->review($c);
        $other = $this->workspace(1, 'FOREIGN');
        $this->actingAs($other['admin'])->getJson($this->sourceUrl($c))->assertNotFound();
        $this->confirm($review)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $command = $this->command($other);
        $command['target_section_id'] = $c['target']->id;
        $this->postJson('/students/progression/review', $command)->assertNotFound();
        $command = $this->command($other);
        $command['rows'][0]['source_enrollment_id'] = $c['records'][0]->id;
        $this->postJson('/students/progression/review', $command)->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->assertDatabaseCount('promotion_batches', 0);
    }

    public function test_old_promotion_entry_links_redirect_to_students_and_history_remains_readable(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/promotions/create')->assertRedirect(route('students.index', ['progression' => 1]));
        $response = $this->get('/promotions/create?source_enrollment_id='.$c['records'][0]->id)->assertRedirect();
        $this->assertStringContainsString('progression_student='.$c['records'][0]->id, $response->headers->get('Location'));
        $this->get('/promotions/create?mode=EXIT&source_enrollment_id='.$c['records'][0]->id)->assertRedirect(route('students.edit', ['student' => $c['records'][0]->student_id, 'enrollment_year' => $c['year']->id]).'#enrollment-heading');
        $review = $this->review($c);
        $batch = $this->confirm($review)->assertOk()->json('batch_id');
        $this->get('/promotions/'.$batch)->assertOk()->assertSee('Recorded outcomes')->assertDontSee('Withdraw / transfer')->assertDontSee('Promotion</span>', false);
    }

    public function test_existing_marks_remain_attached_to_the_unchanged_source_enrollment(): void
    {
        $c = $this->context();
        $c['next'] = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $c['next']->forceFill(['status' => 'ACTIVE'])->save();
        $c['target'] = Section::factory()->create(['capacity' => null]);
        $c['records'] = [$c['enrollment']];
        app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $this->payload($c));
        $before = DB::table('student_marks')->where('enrollment_id', $c['enrollment']->id)->first();
        $this->confirm($this->review($c))->assertOk();
        $this->assertEquals($before, DB::table('student_marks')->where('enrollment_id', $c['enrollment']->id)->first());
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id]);
    }

    public function test_authorization_is_rechecked_when_confirming_a_review(): void
    {
        $c = $this->workspace();
        $staff = $this->user($c, ['student.view', 'student.update']);
        $review = $this->actingAs($staff)->postJson('/students/progression/review', $this->command($c))->assertOk()->json();
        app(AccessManager::class)->assignRoles($c['admin'], $staff, []);
        $this->confirm($review)->assertForbidden();
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }
}

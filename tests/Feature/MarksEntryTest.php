<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\StudentMark;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use App\Services\ExamSubjectService;
use App\Services\MarksEntryService;
use App\Services\ProvisionSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class MarksEntryTest extends TestCase
{
    use BuildsMarksContext;
    use RefreshDatabase;

    public function test_bulk_drafts_totals_audit_and_exam_lifecycle(): void
    {
        $c = $this->context();
        $other = $this->enroll($c, 2);
        $data = $this->payload($c);
        $data['rows'][] = array_replace($data['rows'][0], ['enrollment_id' => $other->id, 'theory_marks' => '0', 'practical_marks' => '0']);
        $this->actingAs($c['admin'])->get('/examinations/marks')->assertOk()->assertSee('Annual Exam');
        $this->get($this->url($c))->assertOk()->assertSee($c['section']->name);
        $this->get($this->url($c).'?section_id='.$c['section']->id)->assertOk()->assertSee($c['enrollment']->student->fullName());
        $this->post($this->url($c), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('student_marks', ['student_id' => $c['enrollment']->student_id, 'total_marks' => '85.00', 'status' => 'DRAFT', 'entered_by' => $c['admin']->id, 'lock_version' => 1]);
        $this->assertDatabaseHas('student_marks', ['student_id' => $other->student_id, 'total_marks' => '0.00']);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'MARKS_ENTRY']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'MARKS_DRAFT_SAVED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXAM_MARKS_ENTRY_STARTED']);
        $this->post('/examinations/exams/'.$c['exam']->id.'/draft')->assertSessionHasErrors();
    }

    public function test_partial_draft_then_completion_submission_and_frozen_row(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin']);
        $this->post($this->url($c), $this->payload($c, ['practical_marks' => null]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['total_marks' => null, 'status' => 'DRAFT']);
        $data = $this->payload($c, ['version' => 1]);
        $data['action'] = 'submit';
        $this->post($this->url($c), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['status' => 'SUBMITTED', 'submitted_by' => $c['admin']->id, 'total_marks' => '85.00', 'lock_version' => 2]);
        $this->assertNotNull(StudentMark::first()->submitted_at);
        $this->post($this->url($c), $this->payload($c, ['version' => 2]))->assertSessionHasErrors();
        $this->get($this->url($c).'?section_id='.$c['section']->id)->assertOk()->assertDontSee('Save draft on this page')->assertSee('SUBMITTED');
    }

    public function test_submission_requires_complete_components_but_absence_is_valid(): void
    {
        $c = $this->context();
        $data = $this->payload($c, ['practical_marks' => null]);
        $data['action'] = 'submit';
        $this->actingAs($c['admin'])->post($this->url($c), $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('student_marks', 0);
        $data = $this->payload($c, ['theory_marks' => null, 'practical_marks' => null, 'is_absent' => true]);
        $data['action'] = 'submit';
        $this->post($this->url($c), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['is_absent' => true, 'total_marks' => null, 'status' => 'SUBMITTED']);
    }

    public function test_invalid_component_values_and_injected_identity_are_rejected(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin']);
        foreach ([['theory_marks' => 71], ['practical_marks' => 31], ['theory_marks' => -1], ['theory_marks' => '2.001'], ['theory_marks' => 10000], ['is_absent' => true], ['remarks' => str_repeat('x', 501)], ['is_absent' => 'maybe'], ['total_marks' => 99], ['student_id' => 1], ['status' => 'VERIFIED'], ['entered_by' => 1]] as $bad) {
            $this->post($this->url($c), $this->payload($c, $bad))->assertSessionHasErrors();
        }$this->assertDatabaseCount('student_marks', 0);
    }

    public function test_theory_only_and_decimal_totals(): void
    {
        $c = $this->context();
        $s = $c['subject'];
        $s->theory_max_marks = '99.99';
        $s->practical_max_marks = '0.01';
        $s->save();
        $this->actingAs($c['admin'])->post($this->url($c), $this->payload($c, ['theory_marks' => '99.99', 'practical_marks' => '0.01']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['total_marks' => '100.00']);
        $s->practical_max_marks = null;
        $s->save();
        $this->post($this->url($c), $this->payload($c, ['version' => 1, 'theory_marks' => '99.99', 'practical_marks' => 1]))->assertSessionHasErrors();
        $this->post($this->url($c), $this->payload($c, ['version' => 1, 'theory_marks' => '99.99', 'practical_marks' => null]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['total_marks' => '99.99']);
    }

    public function test_teacher_is_scoped_by_subject_year_class_and_section(): void
    {
        $c = $this->context();
        $teacher = $this->teacher($c);
        $other = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'B']);
        $e = $this->enroll($c, 1, $other);
        $this->actingAs($teacher)->get('/examinations/marks')->assertOk()->assertSee($c['subject']->subject->name);
        $this->get($this->url($c))->assertOk()->assertViewHas('sections', fn ($s) => $s->total() === 1 && $s->first()->id === $c['section']->id);
        $this->get($this->url($c).'?section_id='.$other->id)->assertForbidden();
        $this->post($this->url($c), $this->payload($c, ['enrollment_id' => $e->id]))->assertSessionHasErrors();
        $this->post($this->url($c), $this->payload($c))->assertSessionHasNoErrors();
        $allocation = ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id]);
        $c['exam']->status = 'DRAFT';
        $c['exam']->save();
        $subject = app(ExamSubjectService::class)->save($c['admin'], $c['exam'], null, ['class_subject_id' => $allocation->id, 'passing_marks' => 33]);
        $this->get('/examinations/marks/'.$subject->id)->assertForbidden();
    }

    public function test_unassigned_class_teacher_and_inactive_assignment_grant_no_access(): void
    {
        $c = $this->context();
        $teacher = $this->teacher($c);
        $assignment = TeacherAssignment::first();
        $assignment->status = 'INACTIVE';
        $assignment->save();
        $this->actingAs($teacher)->get($this->url($c))->assertForbidden();
        $this->get('/examinations/marks')->assertOk()->assertDontSee($c['subject']->subject->name);
        $assignment->status = 'ACTIVE';
        $assignment->assignment_type = 'CLASS_TEACHER';
        $assignment->subject_id = null;
        $assignment->class_subject_id = null;
        $assignment->save();
        $this->get($this->url($c))->assertForbidden();
    }

    public function test_elevated_access_still_requires_action_permissions(): void
    {
        $c = $this->context();
        $viewer = $this->user($c, ['marks.view', 'marks.verify']);
        $this->actingAs($viewer)->get($this->url($c).'?section_id='.$c['section']->id)->assertOk()->assertDontSee('Save draft on this page');
        $this->post($this->url($c), $this->payload($c))->assertForbidden();
        $entrant = $this->user($c, ['marks.view', 'marks.verify', 'marks.enter']);
        $this->actingAs($entrant);
        $data = $this->payload($c);
        $data['action'] = 'submit';
        $this->post($this->url($c), $data)->assertForbidden();
        $this->post($this->url($c), $this->payload($c))->assertSessionHasNoErrors();
        $this->post($this->url($c), $this->payload($c, ['version' => 1, 'theory_marks' => 50]))->assertForbidden();
    }

    public function test_optimistic_concurrency_prevents_stale_overwrites(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin']);
        $data = $this->payload($c);
        $this->post($this->url($c), $data)->assertSessionHasNoErrors();
        $this->post($this->url($c), $data)->assertSessionHasErrors();
        $this->post($this->url($c), $this->payload($c, ['version' => 1, 'theory_marks' => 55]))->assertSessionHasNoErrors();
        $this->post($this->url($c), $this->payload($c, ['version' => 1, 'theory_marks' => 50]))->assertSessionHasErrors();
        $this->assertDatabaseHas('student_marks', ['theory_marks' => '55.00', 'lock_version' => 2]);
    }

    public function test_bulk_failure_rolls_back_all_rows_and_exam_state(): void
    {
        $c = $this->context();
        $data = $this->payload($c);
        $data['rows'][] = array_replace($data['rows'][0], ['enrollment_id' => 99999999]);
        $this->actingAs($c['admin'])->post($this->url($c), $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('student_marks', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'MARKS_DRAFT_SAVED']);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'OPEN']);
    }

    public function test_audit_failure_rolls_back_marks(): void
    {
        $c = $this->context();
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $this->payload($c));
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('student_marks', 0);
        $this->assertDatabaseHas('exams', ['id' => $c['exam']->id, 'status' => 'OPEN']);
    }

    public function test_later_exam_states_and_inactive_year_freeze_entry(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin']);
        foreach (['DRAFT', 'VERIFICATION', 'APPROVED', 'PUBLISHED', 'CLOSED'] as $state) {
            $c['exam']->status = $state;
            $c['exam']->save();
            $this->post($this->url($c), $this->payload($c))->assertSessionHasErrors();
        }
        $c['exam']->status = 'OPEN';
        $c['exam']->save();
        $c['year']->status = 'CLOSED';
        $c['year']->save();
        $this->post($this->url($c), $this->payload($c))->assertSessionHasErrors();
        $this->assertDatabaseCount('student_marks', 0);
    }

    public function test_inactive_parents_and_roster_members_reject_entry(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin']);
        foreach ([$c['section'], $c['class'], $c['term'], $c['allocation'], $c['subject'], $c['enrollment'], $c['enrollment']->student] as $model) {
            $model->status = 'INACTIVE';
            $model->save();
            $this->post($this->url($c), $this->payload($c))->assertSessionHasErrors();
            $model->status = 'ACTIVE';
            $model->save();
        }
        $this->assertDatabaseCount('student_marks', 0);
    }

    public function test_roster_and_section_pagination_and_search(): void
    {
        $c = $this->context();
        for ($i = 2; $i <= 23; $i++) {
            $this->enroll($c, $i);
        }
        $this->actingAs($c['admin'])->get($this->url($c).'?section_id='.$c['section']->id)->assertOk()->assertViewHas('roster', fn ($r) => $r->total() === 23 && $r->count() === 20)->assertSee('Submit editable rows on this page');
        $this->get($this->url($c).'?section_id='.$c['section']->id.'&page=2')->assertOk()->assertViewHas('roster', fn ($r) => $r->count() === 3);
        $this->get('/examinations/marks?q=NONEXISTENT')->assertOk()->assertViewHas('subjects', fn ($s) => $s->total() === 0);
        $this->get('/examinations/marks?academic_year_id='.$c['year']->id)->assertOk()->assertViewHas('subjects', fn ($s) => $s->total() === 1);
    }

    public function test_noop_draft_does_not_add_audits_or_increment_version(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->post($this->url($c), $this->payload($c))->assertSessionHasNoErrors();
        $count = DB::table('audit_logs')->count();
        $this->post($this->url($c), $this->payload($c, ['version' => 1]))->assertSessionHasNoErrors();
        $this->assertSame($count, DB::table('audit_logs')->count());
        $this->assertSame(1, StudentMark::first()->lock_version);
    }

    public function test_duplicate_rows_and_oversize_batches_fail(): void
    {
        $c = $this->context();
        $data = $this->payload($c);
        $data['rows'] = array_fill(0, 2, $data['rows'][0]);
        $this->actingAs($c['admin'])->post($this->url($c), $data)->assertSessionHasErrors();
        $data['rows'] = array_fill(0, 21, $data['rows'][0]);
        $this->post($this->url($c), $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('student_marks', 0);
    }

    public function test_guests_unprivileged_and_other_schools_are_denied(): void
    {
        $c = $this->context();
        $this->get('/examinations/marks')->assertRedirect('/login');
        $user = $this->user($c, []);
        $this->actingAs($user)->get('/examinations/marks')->assertForbidden();
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other school', $other->email);
        $other = $other->fresh();
        $this->actingAs($other)->get($this->url($c))->assertNotFound();
        $this->post($this->url($c), $this->payload($c))->assertNotFound();
    }

    public function test_guided_year_exam_class_selection_is_assignment_scoped(): void
    {
        $c = $this->context();
        $teacher = $this->teacher($c);
        $this->actingAs($teacher)->get('/examinations/marks/select')->assertOk()->assertSee('2026-27');
        $this->get('/examinations/marks/select?academic_year_id='.$c['year']->id)->assertOk()->assertSee('Annual Exam')->assertSee($c['class']->name);
        $this->get('/examinations/marks/select?academic_year_id=999999')->assertNotFound();
        $this->get('/examinations/marks?class_id=999999')->assertOk()->assertViewHas('subjects', fn ($s) => $s->total() === 0);
    }

    public function test_class_wide_assignment_covers_sections_and_inactive_teacher_revokes_access(): void
    {
        $c = $this->context();
        $teacher = $this->teacher($c);
        $a = TeacherAssignment::first();
        $a->section_id = null;
        $a->save();
        $b = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'B']);
        $e = $this->enroll($c, 1, $b);
        $this->actingAs($teacher)->get($this->url($c).'?section_id='.$b->id)->assertOk()->assertSee($e->student->fullName());
        $employee = $teacher->employee;
        $employee->status = 'INACTIVE';
        $employee->save();
        $this->get($this->url($c))->assertForbidden();
        $this->post($this->url($c), $this->payload($c))->assertForbidden();
    }

    public function test_wrong_section_class_and_year_students_are_rejected(): void
    {
        $c = $this->context();
        $wrong = Section::factory()->create();
        $data = $this->payload($c);
        $data['section_id'] = $wrong->id;
        $this->actingAs($c['admin'])->post($this->url($c), $data)->assertSessionHasErrors();
        $otherYear = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $otherYear->status = 'ACTIVE';
        $otherYear->save();
        $e = app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => $c['enrollment']->student_id, 'academic_year_id' => $otherYear->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'roll_number' => 1, 'joined_at' => '2027-04-01']);
        $this->post($this->url($c), $this->payload($c, ['enrollment_id' => $e->id]))->assertSessionHasErrors();
        $this->assertDatabaseCount('student_marks', 0);
    }

    public function test_mixed_submitted_and_draft_rows_only_offer_editable_inputs(): void
    {
        $c = $this->context();
        $other = $this->enroll($c, 2);
        $data = $this->payload($c);
        $data['action'] = 'submit';
        $this->actingAs($c['admin'])->post($this->url($c), $data)->assertSessionHasNoErrors();
        $this->get($this->url($c).'?section_id='.$c['section']->id)->assertOk()->assertDontSee('name="rows[0][enrollment_id]"', false)->assertSee('name="rows[1][enrollment_id]"', false)->assertSee('Save draft on this page');
        $data = $this->payload($c, ['enrollment_id' => $other->id]);
        $this->post($this->url($c), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('student_marks', 2);
    }

    public function test_practical_only_zero_marks_and_reverting_absence_in_draft(): void
    {
        $c = $this->context();
        $c['subject']->theory_max_marks = null;
        $c['subject']->practical_max_marks = 100;
        $c['subject']->save();
        $this->actingAs($c['admin'])->post($this->url($c), $this->payload($c, ['theory_marks' => null, 'practical_marks' => null, 'is_absent' => true]))->assertSessionHasNoErrors();
        $this->post($this->url($c), $this->payload($c, ['version' => 1, 'theory_marks' => null, 'practical_marks' => 0]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_marks', ['is_absent' => false, 'total_marks' => '0.00', 'lock_version' => 2]);
    }

    public function test_database_constraints_protect_identity_totals_and_workflow(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->post($this->url($c), $this->payload($c))->assertSessionHasNoErrors();
        $base = StudentMark::first()->getAttributes();
        unset($base['id']);
        try {
            DB::table('student_marks')->insert($base);
            $this->fail('Duplicate accepted');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }
        foreach ([['total_marks' => 84], ['theory_marks' => -1], ['is_absent' => true], ['status' => 'INVALID'], ['status' => 'SUBMITTED'], ['academic_year_id' => 999999], ['exam_subject_id' => 999999]] as $bad) {
            try {
                DB::table('student_marks')->where('id', StudentMark::first()->id)->update($bad);
                $this->fail('Invalid data accepted');
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[0], ['23000', 'HY000']);
            }
        }
    }

    public function test_service_rechecks_stale_exam_and_revoked_actor_permissions(): void
    {
        $c = $this->context();
        $actor = $this->teacher($c);
        $c['subject']->load('exam');
        $c['exam']->status = 'CLOSED';
        $c['exam']->save();
        try {
            app(MarksEntryService::class)->save($actor, $c['subject'], $c['section'], $this->payload($c));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('context', $e->errors());
        }
        $c['exam']->status = 'OPEN';
        $c['exam']->save();
        $fresh = $actor->fresh();
        $fresh->is_active = false;
        $fresh->save();
        try {
            app(MarksEntryService::class)->save($actor, $c['subject'], $c['section'], $this->payload($c));
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('student_marks', 0);
    }

    public function test_validation_redirect_keeps_stale_version_and_binds_old_values_to_enrollment(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin']);
        $this->post($this->url($c), $this->payload($c))->assertSessionHasNoErrors();
        $page = $this->url($c).'?section_id='.$c['section']->id;
        $this->from($page)->post($this->url($c), $this->payload($c, ['version' => 0, 'theory_marks' => 50]))->assertSessionHasErrors();
        $this->get($page)->assertOk()->assertSee('name="rows[0][version]" value="0"', false)->assertSee('value="50"', false);
        $other = $this->enroll($c, 2);
        $c['enrollment']->roll_number = 3;
        $c['enrollment']->save();
        $this->from($page)->post($this->url($c), $this->payload($c, ['version' => 0, 'theory_marks' => 50]))->assertSessionHasErrors();
        $this->get($page)->assertOk()->assertDontSee('value="50"', false);
    }
}

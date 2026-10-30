<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SyncSubjectClasses;
use App\Support\CurrentSchool;
use App\Support\SubjectClassState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class SubjectWorkspaceTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $subject = $c['allocation']->subject;
        $subject->update(['name' => 'Mathematics', 'code' => 'MATH']);
        $c['class']->update(['name' => 'Class 1', 'code' => 'CLASS-1']);
        $second = SchoolClass::factory()->create(['name' => 'Class 2', 'code' => 'CLASS-2']);
        $third = SchoolClass::factory()->create(['name' => 'Class 3', 'code' => 'CLASS-3']);
        $fourth = SchoolClass::factory()->create(['name' => 'Class 4', 'code' => 'CLASS-4']);
        $base = ['subject_id' => $subject->id, 'academic_year_id' => $c['year']->id];
        $editable = ClassSubject::factory()->create($base + ['class_id' => $second->id, 'theory_max_marks' => 50, 'passing_marks' => 20]);
        $retained = ClassSubject::factory()->create($base + ['class_id' => $third->id, 'status' => 'INACTIVE', 'theory_max_marks' => 80, 'practical_max_marks' => 20, 'passing_marks' => 30]);
        $oldYear = AcademicYear::create(['name' => '2025-26', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31']);
        $oldYear->forceFill(['status' => 'CLOSED'])->save();
        ClassSubject::factory()->create(['academic_year_id' => $oldYear->id, 'subject_id' => $subject->id, 'class_id' => $c['class']->id]);

        return array_replace($c, compact('subject', 'second', 'third', 'fourth', 'editable', 'retained', 'oldYear'));
    }

    private function payload(array $c, array $classes): array
    {
        return ['academic_year_id' => $c['year']->id, 'class_ids' => $classes, 'setup_revision' => SubjectClassState::revision($c['subject'], $c['year'])];
    }

    private function url(array $c): string
    {
        return '/academics/subjects/'.$c['subject']->id.'/classes';
    }

    public function test_one_subjects_workspace_shows_current_class_counts_and_direct_links(): void
    {
        $c = $this->workspace();
        $response = $this->actingAs($c['admin'])->get('/academics/subjects?'.http_build_query(['page' => 1, 'tables' => ['page' => ['search' => 'Math', 'length' => 10]]]))->assertOk()->assertSee('By class')->assertSee('2 classes')->assertSee('View classes for Mathematics')->assertDontSee('aria-label="Class Subjects"', false);
        $this->assertSame(2, $response->viewData('subjects')->first()->current_classes_count);
        $this->get('/academics/class-subjects?subject_id='.$c['subject']->id.'&academic_year_id='.$c['year']->id)->assertOk()->assertSee('Manage classes')->assertSee('Marks settings')->assertViewHas('allocations', fn ($rows) => $rows->total() === 3);
    }

    public function test_checklist_marks_existing_protected_and_retained_classes_and_preserves_context(): void
    {
        $c = $this->workspace();
        $catalog = ['tables' => ['page' => ['search' => 'Math', 'length' => 10]], 'page' => 2];
        $this->actingAs($c['admin'])->get($this->url($c).'?'.http_build_query(['catalog' => $catalog]))->assertOk()->assertSee('Manage classes')->assertSee('Used by teachers or exams')->assertSee('Previously assigned')->assertViewHas('locked', [$c['class']->id])->assertViewHas('selected', [$c['class']->id, $c['second']->id])->assertSee('name="catalog[page]"', false);
    }

    public function test_one_save_adds_and_restores_classes_without_changing_existing_marks(): void
    {
        $c = $this->workspace();
        $ids = [$c['class']->id, $c['second']->id, $c['third']->id, $c['fourth']->id];
        $response = $this->actingAs($c['admin'])->put($this->url($c), $this->payload($c, $ids) + ['catalog' => ['page' => 2]])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('ACTIVE', $c['retained']->fresh()->status->value);
        $this->assertSame('80.00', $c['retained']->fresh()->theory_max_marks);
        $this->assertSame('20.00', $c['retained']->fresh()->practical_max_marks);
        $this->assertSame('50.00', $c['editable']->fresh()->theory_max_marks);
        $this->assertDatabaseHas('class_subjects', ['class_id' => $c['fourth']->id, 'subject_id' => $c['subject']->id, 'theory_max_marks' => null, 'status' => 'ACTIVE']);
        $this->assertSame(5, ClassSubject::count());
        $before = DB::table('audit_logs')->count();
        $this->put($this->url($c), $this->payload($c, $ids))->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertStringContainsString('catalog%5Bpage%5D=2', $response->headers->get('Location'));
    }

    public function test_unchecking_unused_classes_requires_confirmation_and_retains_settings_and_identity(): void
    {
        $c = $this->workspace();
        $data = $this->payload($c, [$c['class']->id]);
        $this->actingAs($c['admin'])->put($this->url($c), $data)->assertSessionHasErrors('confirm_removals');
        $this->assertSame('ACTIVE', $c['editable']->fresh()->status->value);
        $this->put($this->url($c), $data + ['confirm_removals' => 1])->assertSessionHasNoErrors();
        $this->assertSame('INACTIVE', $c['editable']->fresh()->status->value);
        $this->assertSame('50.00', $c['editable']->fresh()->theory_max_marks);
        $this->assertDatabaseCount('class_subjects', 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CLASS_SUBJECT_DEACTIVATED', 'entity_id' => $c['editable']->id]);
        $this->put($this->url($c), $this->payload($c, [$c['class']->id, $c['second']->id]))->assertSessionHasNoErrors();
        $this->assertSame('ACTIVE', $c['editable']->fresh()->status->value);
        $this->assertDatabaseCount('class_subjects', 4);
    }

    public function test_removal_rechecks_exam_and_teacher_dependencies_on_all_entry_points(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->put($this->url($c), $this->payload($c, [$c['second']->id, $c['fourth']->id]) + ['confirm_removals' => 1])->assertSessionHasErrors('class_ids');
        $this->assertDatabaseMissing('class_subjects', ['class_id' => $c['fourth']->id, 'subject_id' => $c['subject']->id]);
        $this->post('/academics/class-subjects/'.$c['allocation']->id.'/deactivate')->assertSessionHasErrors('status');
        // Inactive teacher assignment history also protects the link.
        $data = $this->payload($c, [$c['class']->id]);
        TeacherAssignment::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $c['second']->id, 'subject_id' => $c['subject']->id, 'class_subject_id' => $c['editable']->id, 'assignment_type' => 'SUBJECT_TEACHER', 'status' => 'INACTIVE']);
        $this->put($this->url($c), $data + ['confirm_removals' => 1])->assertSessionHasErrors('class_ids');
        $this->assertSame('ACTIVE', $c['editable']->fresh()->status->value);
    }

    public function test_stale_membership_and_marks_changes_are_not_overwritten(): void
    {
        $c = $this->workspace();
        $data = $this->payload($c, [$c['class']->id, $c['second']->id, $c['fourth']->id]);
        $c['editable']->update(['theory_max_marks' => 60]);
        $this->actingAs($c['admin'])->from($this->url($c))->put($this->url($c), $data)->assertSessionHasErrors('setup_revision');
        $this->assertDatabaseMissing('class_subjects', ['class_id' => $c['fourth']->id, 'subject_id' => $c['subject']->id]);
        $this->withCookie(config('session.cookie'), session()->getId())->get($this->url($c))->assertOk()->assertViewHas('selected', [$c['class']->id, $c['second']->id]);
    }

    public function test_failure_after_a_creation_rolls_back_all_memberships_and_audit_records(): void
    {
        $c = $this->workspace();
        $before = DB::table('audit_logs')->count();
        $logger = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($logger) {
            if ($args[1] === 'CLASS_SUBJECT_ACTIVATED') {
                throw new \RuntimeException('Audit unavailable');
            }

            return $logger->record(...$args);
        });
        try {
            app(SyncSubjectClasses::class)->save($c['admin'], $c['subject'], $this->payload($c, [$c['class']->id, $c['second']->id, $c['fourth']->id, $c['third']->id]));
            $this->fail('A partial save was committed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertSame('INACTIVE', $c['retained']->fresh()->status->value);
        $this->assertDatabaseMissing('class_subjects', ['class_id' => $c['fourth']->id, 'subject_id' => $c['subject']->id]);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_inactive_subjects_closed_years_and_inactive_new_classes_are_rejected(): void
    {
        $c = $this->workspace();
        $url = $this->url($c);
        $this->actingAs($c['admin'])->get($url.'?academic_year_id='.$c['oldYear']->id)->assertOk()->assertViewHas('editable', false)->assertSee('This academic year is read-only.');
        $data = $this->payload($c, [$c['class']->id, $c['second']->id]);
        $data['academic_year_id'] = $c['oldYear']->id;
        $this->put($url, $data)->assertSessionHasErrors('academic_year_id');
        $c['fourth']->forceFill(['status' => 'INACTIVE'])->save();
        $this->put($url, $this->payload($c, [$c['class']->id, $c['second']->id, $c['fourth']->id]))->assertSessionHasErrors('class_ids');
        $c['subject']->forceFill(['status' => 'INACTIVE'])->save();
        $this->get($url)->assertOk()->assertViewHas('editable', false);
        $this->put($url, $this->payload($c, [$c['class']->id, $c['second']->id]))->assertSessionHasErrors('subject');
    }

    public function test_readers_cannot_manage_and_foreign_school_inputs_cannot_be_assigned(): void
    {
        $c = $this->workspace();
        $reader = $this->user($c, ['subject.view']);
        $this->actingAs($reader)->get('/academics/subjects')->assertOk()->assertSee('View classes for Mathematics')->assertDontSee('Add subject');
        $this->get($this->url($c))->assertForbidden();
        $this->put($this->url($c), $this->payload($c, []))->assertForbidden();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = SchoolClass::factory()->create();
        $foreignSubject = Subject::factory()->create();
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->actingAs($c['admin'])->put($this->url($c), $this->payload($c, [$c['class']->id, $c['second']->id, $foreign->id]))->assertSessionHasErrors('class_ids.2');
        $this->get('/academics/subjects/'.$foreignSubject->id.'/classes')->assertNotFound();
    }

    public function test_empty_selection_cannot_bypass_protection_and_no_current_year_is_explicit(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->put($this->url($c), ['classes_present' => 1, 'academic_year_id' => $c['year']->id, 'setup_revision' => SubjectClassState::revision($c['subject'], $c['year']), 'confirm_removals' => 1])->assertSessionHasErrors('class_ids');
        $c['year']->forceFill(['is_current' => false])->save();
        $this->get('/academics/subjects')->assertOk()->assertSee('No current year')->assertDontSee('Not assigned');
    }

    public function test_marks_settings_keep_class_and_year_identity_and_back_context(): void
    {
        $c = $this->workspace();
        $state = ['catalog' => ['page' => 2, 'tables' => ['page' => ['search' => 'Math', 'length' => 10]]], 'mapping' => ['subject_id' => $c['subject']->id, 'academic_year_id' => $c['year']->id]];
        $this->actingAs($c['admin'])->get('/academics/class-subjects/'.$c['editable']->id.'/edit?'.http_build_query($state))->assertOk()->assertSee('name="catalog[page]"', false);
        $response = $this->put('/academics/class-subjects/'.$c['editable']->id, ['theory_max_marks' => 70, 'practical_max_marks' => null, 'passing_marks' => 30] + $state)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertStringContainsString('catalog%5Bpage%5D=2', $response->headers->get('Location'));
        $this->assertSame('70.00', $c['editable']->fresh()->theory_max_marks);
        $this->assertSame($c['second']->id, $c['editable']->fresh()->class_id);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use App\Services\MarksEntryService;
use App\Services\PromotionService;
use App\Services\StudentService;
use App\Support\CurrentSchool;
use App\Support\StudentEnrollmentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class StudentEnrollmentEditorTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $c['enrollment']->student->update(['aadhaar_number' => '234567890123']);
        $c['class']->update(['name' => 'Class 1']);
        $otherClass = SchoolClass::factory()->create(['name' => 'Class 2']);
        $otherSection = Section::factory()->create(['class_id' => $otherClass->id]);
        $nextYear = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $nextYear->forceFill(['status' => 'ACTIVE'])->save();

        return $c + compact('otherClass', 'otherSection', 'nextYear');
    }

    private function inline(array $c, ?Student $student = null, array $changes = []): array
    {
        $record = $student?->enrollments()->where('academic_year_id', $c['year']->id)->first();

        return array_replace(['action' => 'save', 'academic_year_id' => $c['year']->id, 'class_id' => $record?->class_id ?? $c['class']->id, 'section_id' => $record?->section_id ?? $c['section']->id, 'roll_mode' => $record ? 'manual' : 'auto', 'roll_number' => $record?->roll_number, 'joined_at' => $record?->joined_at->format('Y-m-d') ?? '2026-04-01', 'revision' => StudentEnrollmentState::revision($c['year']->fresh(), $student?->fresh(), $record)], $changes);
    }

    private function studentInput(array $c, ?Student $student = null, array $changes = []): array
    {
        $fields = $student ? Arr::only($student->fresh()->getAttributes(), StudentService::FIELDS) : ['first_name' => 'Aarav', 'last_name' => 'Sharma', 'admission_number' => 'NEW-101', 'admission_date' => '2026-04-01'];

        $fields['aadhaar_number'] = $student?->aadhaar_number ?? '234567890123';

        return array_replace($fields + ['enrollment' => $this->inline($c, $student)], $changes);
    }

    public function test_new_student_guardian_photo_and_enrollment_save_together_with_automatic_roll(): void
    {
        Storage::fake('local');
        $c = $this->workspace();
        $data = $this->studentInput($c, changes: ['photo' => UploadedFile::fake()->image('student.jpg'), 'guardians_present' => '1', 'guardians' => [0 => ['kind' => 'new', 'details' => ['first_name' => 'Anita', 'phone' => '9876543210', 'same_address' => true], 'relationship' => 'MOTHER', 'is_emergency_contact' => true]], 'primary_guardian' => '0']);
        $this->actingAs($c['admin'])->get('/students/create')->assertOk()->assertSee('data-enrollment-editor', false);
        $this->post('/students', $data)->assertRedirect()->assertSessionHasNoErrors();
        $studentId = DB::table('students')->where('admission_number', 'NEW-101')->value('id');
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $studentId, 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'roll_number' => 2, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('student_guardians', ['student_id' => $studentId, 'is_primary' => true]);
        foreach (['STUDENT_CREATED', 'GUARDIAN_CREATED', 'STUDENT_GUARDIAN_LINKED', 'ENROLLMENT_CREATED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
        Storage::disk('local')->assertExists(DB::table('students')->where('id', $studentId)->value('photo_path'));
    }

    public function test_automatic_rolls_are_allocated_at_save_and_do_not_reuse_inactive_reservations(): void
    {
        $c = $this->workspace();
        app(EnrollmentService::class)->setStatus($c['admin'], $c['enrollment'], EnrollmentStatus::Inactive);
        $data = $this->studentInput($c);
        $this->actingAs($c['admin'])->post('/students', $data)->assertRedirect()->assertSessionHasNoErrors();
        $data['admission_number'] = 'NEW-102';
        $this->post('/students', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3], DB::table('student_enrollments')->orderBy('roll_number')->pluck('roll_number')->all());
    }

    public function test_duplicate_roll_or_full_section_rolls_back_student_and_photo(): void
    {
        Storage::fake('local');
        $c = $this->workspace();
        $data = $this->studentInput($c, changes: ['enrollment' => $this->inline($c, changes: ['roll_mode' => 'manual', 'roll_number' => 1]), 'photo' => UploadedFile::fake()->image('student.png')]);
        $this->actingAs($c['admin'])->post('/students', $data)->assertSessionHasErrors('enrollment.roll_number');
        $c['section']->update(['capacity' => 1]);
        $data['enrollment'] = $this->inline($c);
        $data['photo'] = UploadedFile::fake()->image('student2.png');
        $this->post('/students', $data)->assertSessionHasErrors('enrollment.section_id');
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('student_enrollments', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_wrong_class_section_and_inactive_choices_are_rejected(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->post('/students', $this->studentInput($c, changes: ['enrollment' => $this->inline($c, changes: ['section_id' => $c['otherSection']->id])]))->assertSessionHasErrors('enrollment.section_id');
        $c['otherClass']->forceFill(['status' => 'INACTIVE'])->save();
        $this->post('/students', $this->studentInput($c, changes: ['enrollment' => $this->inline($c, changes: ['class_id' => $c['otherClass']->id, 'section_id' => $c['otherSection']->id])]))->assertSessionHasErrors('enrollment.section_id');
        $this->assertDatabaseCount('students', 1);
    }

    public function test_enrollment_only_edit_updates_roll_and_joining_date_without_changing_identity(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        $data = $this->studentInput($c, $student, ['enrollment' => $this->inline($c, $student, ['roll_number' => 7, 'joined_at' => '2026-04-02'])]);
        $this->actingAs($c['admin'])->put('/students/'.$student->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'roll_number' => 7, 'joined_at' => '2026-04-02']);
        $this->assertDatabaseCount('student_enrollments', 1);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'STUDENT_UPDATED', 'entity_id' => $student->id]);
    }

    public function test_unused_placement_correction_requires_explicit_action_and_keeps_enrollment_id(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        $inline = $this->inline($c, $student, ['class_id' => $c['otherClass']->id, 'section_id' => $c['otherSection']->id, 'roll_mode' => 'auto']);
        $this->actingAs($c['admin'])->put('/students/'.$student->id, $this->studentInput($c, $student, ['enrollment' => $inline]))->assertSessionHasErrors('enrollment.class_id');
        $inline['correct_placement'] = true;
        $this->put('/students/'.$student->id, $this->studentInput($c, $student, ['enrollment' => $inline]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'class_id' => $c['otherClass']->id, 'section_id' => $c['otherSection']->id, 'roll_number' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ENROLLMENT_PLACEMENT_CORRECTED', 'entity_id' => $c['enrollment']->id]);
        $this->assertDatabaseCount('student_enrollments', 1);
    }

    public function test_marks_protect_placement_but_allow_existing_roll_and_date_edits(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $this->payload($c));
        $this->assertTrue(StudentEnrollmentState::isUsed($c['enrollment']));
        $inline = $this->inline($c, $student, ['class_id' => $c['otherClass']->id, 'section_id' => $c['otherSection']->id, 'correct_placement' => true]);
        $this->actingAs($c['admin'])->put('/students/'.$student->id, $this->studentInput($c, $student, ['enrollment' => $inline]))->assertSessionHasErrors('enrollment.class_id');
        $this->put('/students/'.$student->id, $this->studentInput($c, $student, ['enrollment' => $this->inline($c, $student, ['roll_number' => 8])]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'class_id' => $c['class']->id, 'roll_number' => 8]);
    }

    public function test_promotion_source_and_target_enrollments_are_both_protected(): void
    {
        $c = $this->workspace();
        app(PromotionService::class)->process($c['admin'], ['operation_key' => (string) Str::uuid(), 'confirmed' => true, 'mode' => 'PLACEMENT', 'source_academic_year_id' => $c['year']->id, 'source_class_id' => $c['class']->id, 'source_section_id' => $c['section']->id, 'target_academic_year_id' => $c['nextYear']->id, 'target_class_id' => $c['otherClass']->id, 'target_section_id' => $c['otherSection']->id, 'joined_at' => '2027-04-01', 'rows' => [['source_enrollment_id' => $c['enrollment']->id, 'action' => 'PROMOTE', 'roll_number' => 1]]]);
        $target = StudentEnrollment::where('academic_year_id', $c['nextYear']->id)->firstOrFail();
        $this->assertTrue(StudentEnrollmentState::isUsed($c['enrollment']));
        $this->assertTrue(StudentEnrollmentState::isUsed($target));
        $this->assertFalse(StudentEnrollmentState::data($c['year'], $c['enrollment']->student)['can_correct']);
        $this->assertFalse(StudentEnrollmentState::data($c['nextYear'], $target->student)['can_correct']);
    }

    public function test_closed_and_exited_enrollments_are_read_only_and_keep_never_changes_them(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        $c['year']->forceFill(['status' => 'CLOSED', 'is_current' => false])->save();
        $this->actingAs($c['admin'])->getJson('/students/enrollment-options?'.http_build_query(['student_id' => $student->id, 'academic_year_id' => $c['year']->id]))->assertOk()->assertJsonPath('can_write', false);
        $this->put('/students/'.$student->id, $this->studentInput($c, $student))->assertForbidden();
        $data = $this->studentInput($c, $student, ['first_name' => 'Updated', 'enrollment' => ['action' => 'keep', 'academic_year_id' => $c['year']->id]]);
        $this->put('/students/'.$student->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'status' => 'ACTIVE', 'roll_number' => 1]);
        $c['year']->forceFill(['status' => 'ACTIVE'])->save();
        $c['enrollment']->forceFill(['status' => 'TRANSFERRED'])->save();
        $this->assertFalse(StudentEnrollmentState::data($c['year']->fresh(), $student->fresh())['can_write']);
    }

    public function test_reactivation_checks_capacity_and_preserves_the_existing_record(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        app(EnrollmentService::class)->setStatus($c['admin'], $c['enrollment'], EnrollmentStatus::Inactive);
        $c['section']->update(['capacity' => 1]);
        $other = $this->enroll($c, 2);
        $inline = $this->inline($c, $student, ['reactivate' => true]);
        $this->actingAs($c['admin'])->put('/students/'.$student->id, $this->studentInput($c, $student, ['enrollment' => $inline]))->assertSessionHasErrors('enrollment.section_id');
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'status' => 'INACTIVE']);
        app(EnrollmentService::class)->setStatus($c['admin'], $other, EnrollmentStatus::Inactive);
        $this->put('/students/'.$student->id, $this->studentInput($c, $student, ['enrollment' => $inline]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'status' => 'ACTIVE']);
    }

    public function test_stale_enrollment_and_concurrently_created_enrollment_are_rejected(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        $data = $this->studentInput($c, $student);
        $c['enrollment']->update(['roll_number' => 3]);
        $this->actingAs($c['admin'])->put('/students/'.$student->id, $data)->assertSessionHasErrors('enrollment.revision');
        $new = Student::factory()->create(['admission_date' => '2026-04-01']);
        $data = $this->studentInput($c, $new);
        app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => $new->id, 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'roll_number' => 4, 'joined_at' => '2026-04-01']);
        $this->put('/students/'.$new->id, $data)->assertSessionHasErrors('enrollment.revision');
        $this->assertDatabaseCount('student_enrollments', 2);
    }

    public function test_metadata_and_writes_require_enrollment_permissions_and_school_scope(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        $creator = $this->user($c, ['student.view', 'student.create']);
        $this->actingAs($creator)->post('/students', $this->studentInput($c))->assertForbidden();
        $this->getJson('/students/enrollment-options?academic_year_id='.$c['year']->id)->assertForbidden();
        $data = $this->studentInput($c);
        unset($data['enrollment']);
        $this->post('/students', $data)->assertRedirect()->assertSessionHasNoErrors();
        $updater = $this->user($c, ['student.view', 'student.update']);
        $this->actingAs($updater)->getJson('/students/enrollment-options?'.http_build_query(['student_id' => $student->id, 'academic_year_id' => $c['year']->id]))->assertOk()->assertJsonPath('enrollment.roll_number', 1);
        $this->getJson('/students/enrollment-options?academic_year_id='.$c['year']->id)->assertForbidden();
        $foreignUser = User::factory()->create();
        app(CurrentSchool::class)->set($foreignUser->school);
        $foreignStudent = Student::factory()->create();
        $foreignYear = AcademicYear::create(['name' => 'FOREIGN', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->actingAs($c['admin'])->getJson('/students/enrollment-options?'.http_build_query(['student_id' => $foreignStudent->id, 'academic_year_id' => $c['year']->id]))->assertNotFound();
        $this->getJson('/students/enrollment-options?academic_year_id='.$foreignYear->id)->assertNotFound();
        $inline = $this->inline($c, changes: ['academic_year_id' => $foreignYear->id]);
        $this->post('/students', $this->studentInput($c, changes: ['enrollment' => $inline]))->assertSessionHasErrors('enrollment.academic_year_id');
    }

    public function test_admission_and_joining_dates_can_be_corrected_together(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        $data = $this->studentInput($c, $student, ['admission_date' => '2026-04-05', 'enrollment' => $this->inline($c, $student, ['joined_at' => '2026-04-05'])]);
        $this->actingAs($c['admin'])->put('/students/'.$student->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'admission_date' => '2026-04-05']);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'joined_at' => '2026-04-05']);
    }

    public function test_invalid_dates_rolls_and_spoofed_nested_fields_are_rejected(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin']);
        foreach ([['joined_at' => '2025-01-01'], ['joined_at' => '2027-04-01'], ['roll_mode' => 'manual', 'roll_number' => 0], ['roll_mode' => 'manual', 'roll_number' => 4294967296], ['roll_mode' => 'manual', 'roll_number' => 1.5]] as $change) {
            $response = $this->post('/students', $this->studentInput($c, changes: ['enrollment' => $this->inline($c, changes: $change)]));
            $response->assertSessionHasErrors(array_key_exists('joined_at', $change) ? 'enrollment.joined_at' : 'enrollment.roll_number');
        }
        $data = $this->studentInput($c);
        $data['enrollment']['student_id'] = $c['enrollment']->student_id;
        $this->post('/students', $data)->assertSessionHasErrors('enrollment');
        $this->assertDatabaseCount('students', 1);
    }

    public function test_failed_enrollment_audit_rolls_back_student_guardian_photo_and_audits(): void
    {
        Storage::fake('local');
        $c = $this->workspace();
        $data = $this->studentInput($c, changes: ['guardian_mode' => 'new', 'guardian' => ['first_name' => 'Anita', 'phone' => '9876543210', 'same_address' => true], 'guardian_relationship' => 'MOTHER', 'guardian_emergency' => true]);
        $before = DB::table('audit_logs')->count();
        $real = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($real) {
            if ($args[1] === 'ENROLLMENT_CREATED') {
                throw new \RuntimeException('Enrollment audit failed');
            }

            return $real->record(...$args);
        });
        try {
            app(StudentService::class)->save($c['admin'], null, $data, UploadedFile::fake()->image('student.png'));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Enrollment audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('guardians', 0);
        $this->assertDatabaseCount('student_guardians', 0);
        $this->assertDatabaseCount('student_enrollments', 1);
        $this->assertDatabaseCount('audit_logs', $before);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_validation_restores_enrollment_and_guardian_drafts_on_the_same_form(): void
    {
        $c = $this->workspace();
        $data = $this->studentInput($c, changes: ['enrollment' => $this->inline($c, changes: ['roll_mode' => 'manual', 'roll_number' => 1]), 'guardians_present' => '1', 'guardians' => [0 => ['kind' => 'new', 'details' => ['first_name' => 'Anita', 'phone' => '9876543210', 'same_address' => true], 'relationship' => 'MOTHER', 'is_emergency_contact' => true]], 'primary_guardian' => '0']);
        $this->actingAs($c['admin'])->from('/students/create')->post('/students', $data)->assertSessionHasErrors('enrollment.roll_number')->assertSessionHasInput('guardians.0.details.first_name', 'Anita');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/students/create')->assertOk()->assertSee('Anita')->assertViewHas('enrollmentDraft', fn ($draft) => $draft['roll_mode'] === 'manual' && (int) $draft['roll_number'] === 1);
        $this->assertDatabaseCount('guardians', 0);
    }
}

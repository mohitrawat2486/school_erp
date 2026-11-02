<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use App\Services\MarksEntryService;
use App\Services\StudentService;
use App\Support\CurrentSchool;
use App\Support\GuardianEditorState;
use App\Support\StudentEnrollmentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class StudentDepartureTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $c['enrollment']->student->update(['first_name' => 'Aarav', 'middle_name' => null, 'last_name' => 'Sharma']);

        return $c;
    }

    private function departure(array $c, string $action = 'WITHDRAW'): array
    {
        $student = $c['enrollment']->student->fresh();

        return array_replace(Arr::only($student->getAttributes(), StudentService::FIELDS), ['aadhaar_number' => $student->aadhaar_number ?? '234567890123']) + [
            'enrollment' => ['action' => 'keep', 'academic_year_id' => $c['year']->id],
            'departure' => ['action' => $action, 'academic_year_id' => $c['year']->id, 'revision' => StudentEnrollmentState::revision($c['year']->fresh(), $student, $c['enrollment']->fresh()), 'operation_key' => (string) Str::uuid(), 'departure_date' => '2026-10-30', 'reason' => 'Family relocation', 'destination_school' => $action === 'TRANSFER' ? 'Green Valley School' : null, 'confirmed' => '1'],
        ];
    }

    public function test_withdrawal_records_date_reason_and_actor_without_deleting_student_or_marks(): void
    {
        $c = $this->workspace();
        app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $this->payload($c));
        $data = $this->departure($c);
        $this->actingAs($c['admin'])->put('/students/'.$c['enrollment']->student_id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'status' => 'WITHDRAWN']);
        $this->assertDatabaseHas('students', ['id' => $c['enrollment']->student_id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('student_marks', ['enrollment_id' => $c['enrollment']->id, 'total_marks' => '85.00']);
        $this->assertDatabaseHas('promotion_outcomes', ['student_id' => $c['enrollment']->student_id, 'source_enrollment_id' => $c['enrollment']->id, 'action' => 'WITHDRAW', 'departure_date' => '2026-10-30', 'notes' => 'Family relocation', 'destination_school' => null]);
        $this->assertDatabaseHas('promotion_batches', ['actor_id' => $c['admin']->id, 'operation_key' => $data['departure']['operation_key']]);
        $this->get('/students/'.$c['enrollment']->student_id)->assertOk()->assertSee('Withdraw')->assertSee('30 Oct 2026')->assertSee('Family relocation');
        $this->assertDatabaseCount('student_enrollments', 1);
    }

    public function test_transfer_keeps_destination_details_and_can_save_other_student_changes(): void
    {
        $c = $this->workspace();
        $data = $this->departure($c, 'TRANSFER');
        $data['first_name'] = 'Updated name';
        $this->actingAs($c['admin'])->put('/students/'.$c['enrollment']->student_id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['id' => $c['enrollment']->student_id, 'first_name' => 'Updated name']);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'status' => 'TRANSFERRED']);
        $this->assertDatabaseHas('promotion_outcomes', ['action' => 'TRANSFER', 'destination_school' => 'Green Valley School', 'departure_date' => '2026-10-30']);
        $this->get('/students/'.$c['enrollment']->student_id)->assertOk()->assertSee('Green Valley School')->assertSee('Family relocation');
    }

    public function test_exact_retry_is_idempotent_including_photo_and_guardian_edits(): void
    {
        Storage::fake('local');
        $c = $this->workspace();
        $student = $c['enrollment']->student->fresh();
        $data = $this->departure($c, 'TRANSFER');
        $data += ['guardians_present' => '1', 'guardian_setup_revision' => GuardianEditorState::student($student), 'guardians' => [0 => ['kind' => 'new', 'details' => ['first_name' => 'Anita', 'phone' => '9876543210', 'same_address' => true], 'relationship' => 'MOTHER', 'is_emergency_contact' => true]], 'primary_guardian' => '0', 'photo' => UploadedFile::fake()->image('student.jpg')];
        $this->actingAs($c['admin']);
        $this->post('/students/'.$student->id, ['_method' => 'PUT', ...$data])->assertRedirect()->assertSessionHasNoErrors();
        $audits = DB::table('audit_logs')->count();
        $photo = DB::table('students')->where('id', $student->id)->value('photo_path');
        $this->post('/students/'.$student->id, ['_method' => 'PUT', ...$data])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('promotion_batches', 1);
        $this->assertDatabaseCount('promotion_outcomes', 1);
        $this->assertDatabaseCount('guardians', 1);
        $this->assertDatabaseCount('student_guardians', 1);
        $this->assertDatabaseCount('audit_logs', $audits);
        $this->assertSame($photo, DB::table('students')->where('id', $student->id)->value('photo_path'));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_reused_confirmation_with_changed_reason_or_student_fields_is_rejected(): void
    {
        $c = $this->workspace();
        $data = $this->departure($c);
        $url = '/students/'.$c['enrollment']->student_id;
        $this->actingAs($c['admin'])->put($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $data['departure']['reason'] = 'Different reason';
        $this->put($url, $data)->assertSessionHasErrors('departure.operation_key');
        $data['departure']['reason'] = 'Family relocation';
        $data['first_name'] = 'Overwrite';
        $this->put($url, $data)->assertSessionHasErrors('departure.operation_key');
        $this->assertDatabaseHas('students', ['id' => $c['enrollment']->student_id, 'first_name' => 'Aarav']);
        $this->assertDatabaseCount('promotion_outcomes', 1);
    }

    public function test_departure_requires_existing_active_eligible_enrollment(): void
    {
        $c = $this->workspace();
        $data = $this->departure($c);
        $url = '/students/'.$c['enrollment']->student_id;
        app(EnrollmentService::class)->setStatus($c['admin'], $c['enrollment'], EnrollmentStatus::Inactive);
        $data = $this->departure($c);
        $this->actingAs($c['admin'])->put($url, $data)->assertSessionHasErrors('departure');
        $data['admission_number'] = 'NEW-EXIT';
        $this->post('/students', $data)->assertSessionHasErrors('departure');
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_closed_year_and_later_enrollment_block_departure(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student;
        $c['year']->forceFill(['is_current' => false, 'status' => 'CLOSED'])->save();
        $this->actingAs($c['admin'])->put('/students/'.$student->id, $this->departure($c))->assertSessionHasErrors('departure');
        $c['year']->forceFill(['status' => 'ACTIVE'])->save();
        $next = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $next->forceFill(['status' => 'ACTIVE'])->save();
        app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => $student->id, 'academic_year_id' => $next->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'roll_number' => 1, 'joined_at' => '2027-04-01']);
        $this->put('/students/'.$student->id, $this->departure($c))->assertSessionHasErrors('departure');
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_dates_reason_confirmation_and_unknown_fields_are_validated(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin']);
        $url = '/students/'.$c['enrollment']->student_id;
        foreach ([['departure_date', '2025-01-01'], ['departure_date', today()->addDay()->toDateString()], ['reason', ''], ['confirmed', '0'], ['operation_key', 'bad'], ['action', 'DELETE']] as [$field,$value]) {
            $data = $this->departure($c);
            $data['departure'][$field] = $value;
            $this->put($url, $data)->assertSessionHasErrors('departure.'.$field);
        }
        $data = $this->departure($c);
        $data['departure']['school_id'] = 999;
        $this->put($url, $data)->assertSessionHasErrors('departure');
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_enrollment_and_departure_cannot_be_staged_together(): void
    {
        $c = $this->workspace();
        $data = $this->departure($c);
        $data['enrollment'] = ['action' => 'save', 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'roll_mode' => 'manual', 'roll_number' => 1, 'joined_at' => '2026-04-01', 'revision' => $data['departure']['revision']];
        $this->actingAs($c['admin'])->put('/students/'.$c['enrollment']->student_id, $data)->assertSessionHasErrors('departure');
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_stale_departure_rejects_overwrites_and_exact_receipt_cannot_be_used_by_another_actor(): void
    {
        $c = $this->workspace();
        $data = $this->departure($c);
        $student = $c['enrollment']->student;
        $student->update(['first_name' => 'Changed']);
        $this->actingAs($c['admin'])->put('/students/'.$student->id, $data)->assertSessionHasErrors('departure.revision');
        $data = $this->departure($c);
        $this->put('/students/'.$student->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $other = $this->user($c, ['student.view', 'student.update']);
        $this->actingAs($other)->put('/students/'.$student->id, $data)->assertSessionHasErrors('departure.operation_key');
        $this->assertDatabaseCount('promotion_outcomes', 1);
    }

    public function test_permissions_and_school_scope_are_enforced(): void
    {
        $c = $this->workspace();
        $data = $this->departure($c);
        $reader = $this->user($c, ['student.view']);
        $this->actingAs($reader)->put('/students/'.$c['enrollment']->student_id, $data)->assertForbidden();
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        $year = AcademicYear::create(['name' => 'PRIVATE', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $data['departure']['academic_year_id'] = $year->id;
        $this->actingAs($c['admin'])->put('/students/'.$c['enrollment']->student_id, $data)->assertSessionHasErrors('departure.academic_year_id');
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_audit_failure_rolls_back_student_contacts_departure_and_photo(): void
    {
        Storage::fake('local');
        $c = $this->workspace();
        $student = $c['enrollment']->student->fresh();
        $data = $this->departure($c, 'TRANSFER');
        $data['first_name'] = 'Changed';
        $data += ['guardians_present' => '1', 'guardian_setup_revision' => GuardianEditorState::student($student), 'guardians' => [0 => ['kind' => 'new', 'details' => ['first_name' => 'Anita', 'phone' => '9876543210', 'same_address' => true], 'relationship' => 'MOTHER', 'is_emergency_contact' => true]], 'primary_guardian' => '0'];
        $before = DB::table('audit_logs')->count();
        $real = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($real) {
            if ($args[1] === 'STUDENT_TRANSFER') {
                throw new \RuntimeException('Departure audit failed');
            }

            return $real->record(...$args);
        });
        try {
            app(StudentService::class)->save($c['admin'], $student, $data, UploadedFile::fake()->image('student.png'));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Departure audit failed', $exception->getMessage());
        }
        $this->assertDatabaseHas('students', ['id' => $student->id, 'first_name' => 'Aarav']);
        $this->assertDatabaseHas('student_enrollments', ['id' => $c['enrollment']->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('promotion_batches', 0);
        $this->assertDatabaseCount('promotion_outcomes', 0);
        $this->assertDatabaseCount('guardians', 0);
        $this->assertDatabaseCount('audit_logs', $before);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_departure_reopens_same_student_form_with_preserved_guardian_draft(): void
    {
        $c = $this->workspace();
        $student = $c['enrollment']->student->fresh();
        $data = $this->departure($c, 'TRANSFER');
        $data['departure']['reason'] = '';
        $data += ['guardians_present' => '1', 'guardian_setup_revision' => GuardianEditorState::student($student), 'guardians' => [0 => ['kind' => 'new', 'details' => ['first_name' => 'Draft guardian', 'phone' => '9876543210', 'same_address' => true], 'relationship' => 'MOTHER', 'is_emergency_contact' => true]], 'primary_guardian' => '0'];
        $url = '/students/'.$student->id.'/edit';
        $this->actingAs($c['admin'])->from($url)->put('/students/'.$student->id, $data)->assertRedirect($url)->assertSessionHasErrors('departure.reason');
        $this->withCookie(config('session.cookie'), session()->getId())->get($url)->assertOk()->assertSee('Draft guardian')->assertSee('Green Valley School')->assertViewHas('departureDraft', fn ($draft) => $draft['action'] === 'TRANSFER' && $draft['destination_school'] === 'Green Valley School');
        $this->assertDatabaseCount('guardians', 0);
        $this->assertDatabaseCount('promotion_outcomes', 0);
    }

    public function test_student_workspace_has_modals_and_old_enrollment_links_still_work(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/students/'.$c['enrollment']->student_id.'/edit')->assertOk()->assertSee('data-enrollment-dialog', false)->assertSee('data-departure-dialog', false)->assertSee('Manage enrollment')->assertSee('Transfer')->assertDontSee('aria-label="Enrollment" title="Enrollment"', false);
        $this->get('/enrollments')->assertOk();
        $this->get('/enrollments/'.$c['enrollment']->id)->assertOk();
        $this->get('/students/'.$c['enrollment']->student_id.'/edit?enrollment_year='.$c['year']->id)->assertOk()->assertViewHas('enrollmentState', fn ($state) => $state['year']['id'] === $c['year']->id);
    }
}

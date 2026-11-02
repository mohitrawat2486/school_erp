<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $c['class']->update(['name' => 'Class 1', 'code' => 'C1']);
        $c['section']->update(['name' => 'A', 'capacity' => null]);

        return $c;
    }

    private function payload(array $c, array $rows = [['Anika', 'IMP-1', '234567890123']], array $fields = ['first_name', 'admission_number', 'aadhaar_number']): array
    {
        return ['rows' => $rows, 'mappings' => $fields, 'settings' => ['date_format' => 'd/m/Y', 'admission_date' => '2026-04-01', 'academic_year_id' => $c['year']->id]];
    }

    private function review(array $payload)
    {
        return $this->postJson('/students/import/validate', $payload)->assertOk();
    }

    private function commit(string $token, bool $warnings = false, int $chunk = 0)
    {
        return $this->postJson('/students/import', ['review_token' => $token, 'chunk' => $chunk, 'include_warnings' => $warnings]);
    }

    public function test_import_requires_its_dedicated_permission_in_addition_to_student_creation(): void
    {
        $c = $this->workspace();
        $this->get('/students/import')->assertRedirect('/login');
        $reader = $this->user($c, ['student.view', 'student.create']);
        $this->actingAs($reader)->get('/students')->assertOk()->assertDontSee('Import students');
        $this->get('/students/import')->assertForbidden();
        $this->postJson('/students/import/validate', $this->payload($c))->assertForbidden();
        $this->commit(fake()->uuid())->assertForbidden();
        $this->actingAs($c['admin'])->get('/students/import')->assertOk()->assertSee('Paste student data')->assertSee('Pasted date format');
        $this->get('/students')->assertOk()->assertSee('Import students');
    }

    public function test_review_is_read_only_and_returns_field_states_and_a_private_review_token(): void
    {
        $c = $this->workspace();
        $before = Student::count();
        $response = $this->actingAs($c['admin'])->review($this->payload($c));
        $response->assertJsonPath('rows.0.status', 'valid')->assertJsonPath('rows.0.fields.aadhaar_number.status', 'valid')->assertJsonPath('summary.valid', 1)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('students', $before);
        $this->assertArrayNotHasKey('data', $response->json('rows.0'));
        $this->assertNotEmpty($response->json('review_token'));
    }

    public function test_missing_required_values_invalid_aadhaar_gender_and_dates_are_blocking(): void
    {
        $c = $this->workspace();
        $payload = $this->payload($c, [['', '', '123', 'INVALID', '31/02/2018']], ['first_name', 'admission_number', 'aadhaar_number', 'gender', 'date_of_birth']);
        $report = $this->actingAs($c['admin'])->review($payload);
        foreach (['first_name', 'admission_number', 'aadhaar_number', 'gender', 'date_of_birth'] as $field) {
            $report->assertJsonPath('rows.0.fields.'.$field.'.status', 'invalid');
        }
        $this->commit($report->json('review_token'), true)->assertOk()->assertJsonPath('imported', 0)->assertJsonPath('skipped', 1);
        $this->assertDatabaseMissing('students', ['admission_number' => '']);
    }

    public function test_normalization_uses_the_explicit_date_format_and_flags_warnings(): void
    {
        $c = $this->workspace();
        $payload = $this->payload($c, [['John Doe', 'IMP-1', '234567890123', 'M', '05/06/2012']], ['student_name', 'admission_number', 'aadhaar_number', 'gender', 'date_of_birth']);
        $this->actingAs($c['admin']);
        $report = $this->review($payload)->assertJsonPath('rows.0.values.gender', 'MALE')->assertJsonPath('rows.0.values.date_of_birth', '2012-06-05')->assertJsonPath('rows.0.status', 'warning');
        $payload['settings']['date_format'] = 'm/d/Y';
        $this->review($payload)->assertJsonPath('rows.0.values.date_of_birth', '2012-05-06');
        $this->commit($report->json('review_token'), false)->assertOk()->assertJsonPath('imported', 0);
        $report = $this->review($this->payload($c, $payload['rows'], $payload['mappings']));
        $this->commit($report->json('review_token'), true)->assertOk()->assertJsonPath('imported', 1);
        $this->assertDatabaseHas('students', ['admission_number' => 'IMP-1', 'first_name' => 'John', 'last_name' => 'Doe', 'gender' => 'MALE', 'date_of_birth' => '2012-06-05']);
    }

    public function test_database_and_in_sheet_admission_duplicates_are_detected_case_insensitively(): void
    {
        $c = $this->workspace();
        $number = $c['enrollment']->student->admission_number;
        $this->actingAs($c['admin'])->review($this->payload($c, [['One', strtolower($number), '234567890123'], ['Two', 'imp-new', '234567890123'], ['Three', 'IMP-NEW', '234567890123']]))
            ->assertJsonPath('summary.invalid', 3)->assertJsonPath('rows.0.fields.admission_number.status', 'invalid')->assertJsonPath('rows.1.fields.admission_number.status', 'invalid')->assertJsonPath('rows.2.fields.admission_number.status', 'invalid');
    }

    public function test_unknown_class_section_and_class_section_mismatch_are_invalid(): void
    {
        $c = $this->workspace();
        $fields = ['first_name', 'admission_number', 'aadhaar_number', 'class', 'section'];
        $this->actingAs($c['admin']);
        $this->review($this->payload($c, [['One', 'IMP-1', '234567890123', 'Grade 15', 'A']], $fields))->assertJsonPath('rows.0.fields.class.status', 'invalid');
        $this->review($this->payload($c, [['One', 'IMP-1', '234567890123', 'Class 1', 'Z']], $fields))->assertJsonPath('rows.0.fields.section.status', 'invalid');
        $other = SchoolClass::factory()->create(['name' => 'Class 2']);
        Section::factory()->create(['class_id' => $other->id, 'name' => 'ONLY-OTHER']);
        $this->review($this->payload($c, [['One', 'IMP-1', '234567890123', 'Class 1', 'ONLY-OTHER']], $fields))->assertJsonPath('rows.0.fields.section.status', 'invalid');
    }

    public function test_placement_reuses_enrollment_constraints_and_allocates_rolls_around_explicit_reservations(): void
    {
        $c = $this->workspace();
        $fields = ['first_name', 'admission_number', 'aadhaar_number', 'class', 'section', 'roll_number'];
        $report = $this->actingAs($c['admin'])->review($this->payload($c, [['Auto', 'IMP-AUTO', '234567890123', 'C1', 'A', ''], ['Manual', 'IMP-MANUAL', '234567890123', 'C1', 'A', '2']], $fields));
        $this->commit($report->json('review_token'), true)->assertOk()->assertJsonPath('imported', 2);
        $student = Student::where('admission_number', 'IMP-AUTO')->firstOrFail();
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $student->id, 'roll_number' => 3, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id]);
        $report = $this->review($this->payload($c, [['Reserved', 'IMP-RESERVED', '234567890123', 'C1', 'A', '2'], ['Zero', 'IMP-ZERO', '234567890123', 'C1', 'A', '0']], $fields));
        $report->assertJsonPath('summary.invalid', 2);
    }

    public function test_duplicate_pasted_roll_numbers_and_section_capacity_are_checked(): void
    {
        $c = $this->workspace();
        $fields = ['first_name', 'admission_number', 'aadhaar_number', 'class', 'section', 'roll_number'];
        $this->actingAs($c['admin'])->review($this->payload($c, [['One', 'IMP-1', '234567890123', 'C1', 'A', '5'], ['Two', 'IMP-2', '234567890123', 'C1', 'A', '5']], $fields))->assertJsonPath('summary.invalid', 2);
        $c['section']->update(['capacity' => 2]);
        $report = $this->review($this->payload($c, [['One', 'IMP-1', '234567890123', 'C1', 'A', '5'], ['Two', 'IMP-2', '234567890123', 'C1', 'A', '6']], $fields));
        $report->assertJsonPath('rows.0.status', 'valid')->assertJsonPath('rows.1.fields.section.status', 'invalid');
    }

    public function test_guardian_phone_email_and_required_contacts_reuse_existing_guardian_rules(): void
    {
        $c = $this->workspace();
        $fields = ['first_name', 'admission_number', 'aadhaar_number', 'guardian_name', 'guardian_phone', 'guardian_email'];
        $this->actingAs($c['admin'])->review($this->payload($c, [['One', 'IMP-1', '234567890123', 'Parent', 'abc!', 'not-email']], $fields))->assertJsonPath('rows.0.fields.guardian_phone.status', 'invalid')->assertJsonPath('rows.0.fields.guardian_email.status', 'invalid');
        $this->review($this->payload($c, [['One', 'IMP-1', '234567890123', '', '9876543210', '']], $fields))->assertJsonPath('rows.0.fields.guardian_name.status', 'invalid');
    }

    public function test_three_guardians_are_saved_with_student_and_primary_contact_using_existing_services(): void
    {
        $c = $this->workspace();
        $fields = ['first_name', 'admission_number', 'aadhaar_number', 'father_name', 'father_phone', 'mother_name', 'mother_phone', 'guardian_name', 'guardian_phone'];
        $report = $this->actingAs($c['admin'])->review($this->payload($c, [['Anika', 'IMP-1', '234567890123', 'Raj Sharma', '9876543210', 'Anita Sharma', '9876543211', 'Ravi Sharma', '9876543212']], $fields));
        $this->commit($report->json('review_token'), true)->assertOk()->assertJsonPath('imported', 1);
        $student = Student::where('admission_number', 'IMP-1')->firstOrFail();
        $this->assertSame(3, $student->guardianLinks()->count());
        $this->assertSame('FATHER', $student->guardianLinks()->where('is_primary', true)->first()->relationship->value);
        $this->assertSame('234567890123', $student->aadhaar_number);
        $this->assertNotSame('234567890123', $student->getRawOriginal('aadhaar_number'));
        $audit = AuditLog::where('action', 'STUDENT_CREATED')->where('entity_id', $student->id)->firstOrFail();
        $this->assertSame('[REDACTED]', $audit->new_values['aadhaar_number']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_IMPORT_CHUNK']);
    }

    public function test_import_revalidates_changed_database_state_and_does_not_accept_unreviewed_rows(): void
    {
        $c = $this->workspace();
        $report = $this->actingAs($c['admin'])->review($this->payload($c));
        Student::factory()->create(['admission_number' => 'IMP-1']);
        $this->commit($report->json('review_token'))->assertOk()->assertJsonPath('imported', 0)->assertJsonPath('skipped', 1);
        $this->postJson('/students/import', ['rows' => $this->payload($c)['rows']])->assertUnprocessable()->assertJsonValidationErrors('review_token');
    }

    public function test_retry_receipts_are_idempotent_and_bound_to_the_user_and_school(): void
    {
        $c = $this->workspace();
        $token = $this->actingAs($c['admin'])->review($this->payload($c))->json('review_token');
        $first = $this->commit($token)->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('replayed', false);
        $this->commit($token)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('imported_rows', $first->json('imported_rows'));
        $this->commit($token, true)->assertConflict();
        $other = $this->user($c, ['student.view', 'student.create', 'student.import']);
        $this->actingAs($other)->commit($token)->assertUnprocessable();
        $this->assertSame(1, Student::where('admission_number', 'IMP-1')->count());
    }

    public function test_chunks_are_atomic_ordered_and_successful_earlier_chunks_remain_retryable(): void
    {
        $c = $this->workspace();
        $rows = array_map(fn ($i) => ['Import', 'BULK-'.$i, '234567890123'], range(1, 101));
        $token = $this->actingAs($c['admin'])->review($this->payload($c, $rows))->json('review_token');
        $this->commit($token, false, 1)->assertConflict();
        $this->commit($token)->assertOk()->assertJsonPath('imported', 100)->assertJsonPath('done', false);
        $this->commit($token, false, 1)->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('done', true);
        $this->commit($token, false, 1)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(101, Student::where('admission_number', 'like', 'BULK-%')->count());
    }

    public function test_audit_failure_rolls_back_the_whole_current_chunk_and_retry_does_not_duplicate(): void
    {
        $c = $this->workspace();
        $token = $this->actingAs($c['admin'])->review($this->payload($c, [['One', 'IMP-1', '234567890123'], ['Two', 'IMP-2', '234567890123']]))->json('review_token');
        $real = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($real) {
            if ($args[1] === 'STUDENT_IMPORT_CHUNK') {
                throw new \RuntimeException('Audit unavailable');
            }

            return $real->record(...$args);
        });
        $this->commit($token)->assertServerError();
        $this->assertSame(0, Student::where('admission_number', 'like', 'IMP-%')->count());
        $this->app->instance(AuditLogger::class, $real);
        $this->commit($token)->assertOk()->assertJsonPath('imported', 2);
    }

    public function test_expired_reviews_invalid_mappings_and_inconsistent_columns_do_not_persist(): void
    {
        $c = $this->workspace();
        $payload = $this->payload($c);
        $this->actingAs($c['admin']);
        $invalid = $payload;
        $invalid['mappings'] = ['first_name', 'first_name', 'aadhaar_number'];
        $this->postJson('/students/import/validate', $invalid)->assertUnprocessable()->assertJsonValidationErrors('mappings');
        $invalid['mappings'] = ['first_name', 'school_id', 'aadhaar_number'];
        $this->postJson('/students/import/validate', $invalid)->assertUnprocessable();
        $invalid = $payload;
        $invalid['rows'][0][] = 'Extra ignored value';
        $this->review($invalid)->assertJsonPath('rows.0.status', 'invalid');
        $token = $this->review($payload)->json('review_token');
        Cache::forget('student-import:'.$c['admin']->school_id.':'.$c['admin']->id.':'.$token);
        $this->commit($token)->assertUnprocessable()->assertJsonValidationErrors('review_token');
    }

    public function test_foreign_references_and_revoked_permissions_cannot_cross_school_boundaries(): void
    {
        $c = $this->workspace();
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        $class = SchoolClass::factory()->create(['name' => 'PRIVATE FOREIGN']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->actingAs($c['admin'])->review($this->payload($c, [['One', 'IMP-1', '234567890123', $class->code, 'A']], ['first_name', 'admission_number', 'aadhaar_number', 'class', 'section']))->assertJsonPath('rows.0.fields.class.status', 'invalid');
        $limited = $this->user($c, ['student.view', 'student.create', 'student.import']);
        $this->actingAs($limited)->review($this->payload($c, [['One', 'IMP-1', '234567890123', 'C1', 'A']], ['first_name', 'admission_number', 'aadhaar_number', 'class', 'section']))->assertJsonPath('rows.0.fields.class.status', 'invalid');
        $token = $this->review($this->payload($c))->json('review_token');
        DB::table('user_roles')->where('user_id', $limited->id)->delete();
        $this->commit($token)->assertForbidden();
    }

    public function test_large_validation_batches_database_lookups_instead_of_querying_each_cell(): void
    {
        $c = $this->workspace();
        $rows = array_map(fn ($i) => ['Import', 'LARGE-'.$i, '234567890123'], range(1, 1200));
        $this->actingAs($c['admin']);
        DB::enableQueryLog();
        $this->review($this->payload($c, $rows))->assertJsonPath('summary.valid', 1200);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(50, $count, 'Reference/duplicate lookups should be batched, not run per cell.');
    }

    public function test_automatic_roll_overflow_is_rejected_during_review(): void
    {
        $c = $this->workspace();
        $c['section']->update(['capacity' => 2]);
        $fields = ['first_name', 'admission_number', 'aadhaar_number', 'class', 'section', 'roll_number'];
        $this->actingAs($c['admin'])->review($this->payload($c, [['Auto', 'IMP-AUTO', '234567890123', 'C1', 'A', ''], ['Last', 'IMP-LAST', '234567890123', 'C1', 'A', '4294967295']], $fields))
            ->assertJsonPath('rows.0.fields.roll_number.status', 'invalid')->assertJsonPath('rows.1.status', 'valid');
    }

    public function test_late_enrollment_failure_reports_the_exact_row_and_rolls_back_the_student(): void
    {
        $c = $this->workspace();
        $token = $this->actingAs($c['admin'])->review($this->payload($c, [['One', 'IMP-1', '234567890123', 'C1', 'A']], ['first_name', 'admission_number', 'aadhaar_number', 'class', 'section']))->json('review_token');
        $this->mock(EnrollmentService::class)->shouldReceive('save')->once()->andThrow(ValidationException::withMessages(['section_id' => 'Placement changed.']));
        $this->commit($token)->assertUnprocessable()->assertJsonValidationErrors('rows.1');
        $this->assertDatabaseMissing('students', ['admission_number' => 'IMP-1']);
        $this->assertSame(0, AuditLog::where('action', 'STUDENT_IMPORT_CHUNK')->count());
    }

    public function test_completed_receipts_survive_review_cache_expiry(): void
    {
        $c = $this->workspace();
        $token = $this->actingAs($c['admin'])->review($this->payload($c))->json('review_token');
        $this->commit($token)->assertOk()->assertJsonPath('imported', 1);
        Cache::forget('student-import:'.$c['admin']->school_id.':'.$c['admin']->id.':'.$token);
        $this->commit($token)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, Student::where('admission_number', 'IMP-1')->count());
    }

    public function test_an_invalid_numeric_roll_does_not_reserve_a_different_valid_integer(): void
    {
        $c = $this->workspace();
        $fields = ['first_name', 'admission_number', 'aadhaar_number', 'class', 'section', 'roll_number'];
        $this->actingAs($c['admin'])->review($this->payload($c, [['Invalid', 'IMP-BAD', '234567890123', 'C1', 'A', '5.5'], ['Valid', 'IMP-OK', '234567890123', 'C1', 'A', '5']], $fields))
            ->assertJsonPath('rows.0.fields.roll_number.status', 'invalid')->assertJsonPath('rows.1.status', 'valid');
    }
}

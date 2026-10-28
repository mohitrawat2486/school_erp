<?php

namespace Tests\Feature;

use App\Models\StudentResult;
use App\Models\User;
use App\Services\ProvisionSchool;
use App\Services\ReportCardContext;
use App\Services\ResultApprovalService;
use App\Services\ResultCalculationService;
use App\Services\ResultSnapshotService;
use App\Services\ResultSourceFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarksContext;
use Tests\Concerns\BuildsResultContext;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use BuildsMarksContext,BuildsResultContext,RefreshDatabase;

    private function report(array $c): StudentResult
    {
        $s = $this->scheme($c);
        $this->locked($c);
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        app(ResultApprovalService::class)->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);

        return StudentResult::first();
    }

    public function test_report_contains_published_identity_totals_remarks_and_signature_lines(): void
    {
        $c = $this->context();
        $c['admin']->school->update(['name' => 'Sample Academy', 'address_line_1' => '12 Academy Road', 'city' => 'Pune']);
        $r = $this->report($c);
        $this->actingAs($c['admin'])->get('/report-cards/'.$r->id)->assertOk()->assertSee('Sample Academy')->assertSee('12 Academy Road')->assertSee($r->report_context['admission_number'])->assertSee($r->report_context['date_of_birth'])->assertSee('85.00')->assertSee('60.00 / 70.00')->assertSee('25.00 / 30.00')->assertSee('Good')->assertSee('Class Teacher signature')->assertSee('Principal signature')->assertSee('@page{size:A4 portrait', false)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_report_uses_captured_identity_branding_and_logo_after_live_changes(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        Storage::disk('local')->put('logos/test.png', $png);
        $c = $this->context();
        $school = $c['admin']->school;
        $school->logo_path = 'logos/test.png';
        $school->name = 'Original Academy';
        $school->save();
        $r = $this->report($c);
        $original = $r->report_context;
        $school->update(['name' => 'New Academy']);
        $student = $c['enrollment']->student;
        $student->admission_number = 'NEW-ADMISSION';
        $student->date_of_birth = '2014-01-01';
        $student->save();
        Storage::disk('local')->delete('logos/test.png');
        $this->actingAs($c['admin'])->get('/report-cards/'.$r->id)->assertOk()->assertSee('Original Academy')->assertDontSee('New Academy')->assertSee($original['admission_number'])->assertDontSee('NEW-ADMISSION')->assertSee('data:image/png;base64,', false);
    }

    public function test_branding_and_birth_date_changes_in_review_require_regeneration(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        $originalName = $c['admin']->school->name;
        DB::table('schools')->where('id', $c['admin']->school_id)->update(['name' => 'Changed']);
        $this->actingAs($c['admin'])->post('/results/'.$b->id, ['action' => 'approve', 'version' => 1])->assertSessionHasErrors('source');
        DB::table('schools')->where('id', $c['admin']->school_id)->update(['name' => $originalName]);
        $student = $c['enrollment']->student;
        $student->date_of_birth = '2010-03-01';
        $student->save();
        $this->post('/results/'.$b->id, ['action' => 'approve', 'version' => 1])->assertSessionHasErrors('source');
    }

    public function test_legacy_missing_identity_does_not_substitute_live_values(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $b = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        $draft = $b->drafts()->first();
        $row = $draft->payload;
        unset($row['admission_number'],$row['date_of_birth']);
        $ctx = $b->calculation_context;
        unset($ctx['report_school']);
        $payload = $ctx;
        $payload['results'] = [$row];
        DB::table('result_drafts')->where('id', $draft->id)->update(['payload' => json_encode($row)]);
        DB::table('result_batches')->where('id', $b->id)->update(['calculation_context' => json_encode($ctx), 'payload_hash' => ResultSourceFingerprint::digest($payload), 'status' => 'PUBLISHED', 'approved_by' => $c['admin']->id, 'approved_at' => now(), 'published_by' => $c['admin']->id, 'published_at' => now()]);
        app(ResultSnapshotService::class)->archive($c['admin'], $b);
        $r = StudentResult::first();
        $this->actingAs($c['admin'])->get('/report-cards/'.$r->id)->assertOk()->assertSee('Not recorded at publication')->assertDontSee($c['enrollment']->student->admission_number);
    }

    public function test_view_only_user_cannot_print_and_guests_cannot_access(): void
    {
        $c = $this->context();
        $r = $this->report($c);
        $this->get('/report-cards/'.$r->id)->assertRedirect('/login');
        $u = $this->user($c, ['result.view']);
        $this->actingAs($u)->get('/report-cards/'.$r->id)->assertForbidden();
        $this->get('/published-results/'.$r->id)->assertOk()->assertDontSee('Print report card');
    }

    public function test_other_school_report_ids_are_hidden(): void
    {
        $c = $this->context();
        $r = $this->report($c);
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other', $other->email);
        $this->actingAs($other->fresh())->get('/report-cards/'.$r->id)->assertNotFound();
    }

    public function test_report_remains_available_after_exam_and_year_close_without_recalculation(): void
    {
        $c = $this->context();
        $r = $this->report($c);
        $this->mock(ResultCalculationService::class)->shouldNotReceive('calculateExam');
        $this->actingAs($c['admin'])->post('/examinations/exams/'.$c['exam']->id.'/close')->assertSessionHasNoErrors();
        $this->post('/academics/academic-years/'.$c['year']->id.'/close')->assertSessionHasNoErrors();
        $this->get('/report-cards/'.$r->id)->assertOk()->assertSee('Achieved');
    }

    public function test_untrusted_logo_urls_are_never_embedded(): void
    {
        $svc = app(ReportCardContext::class);
        foreach (['https://example.com/logo.png', 'javascript:alert(1)', 'data:image/svg+xml;base64,AAAA'] as $logo) {
            $this->assertNull($svc->logo(['logo' => $logo]));
        }
    }

    public function test_report_print_link_is_available_from_snapshot_and_class_results(): void
    {
        $c = $this->context();
        $r = $this->report($c);
        $this->actingAs($c['admin'])->get('/published-results/'.$r->id)->assertOk()->assertSee(route('report-cards.show', $r));
        $this->get('/class-results?'.http_build_query(['academic_year_id' => $r->academic_year_id, 'exam_id' => $r->exam_id, 'class_id' => $r->class_id]))->assertOk()->assertSee(route('report-cards.show', $r));
    }

    public function test_report_fields_are_escaped_and_no_snapshots_are_modified(): void
    {
        $c = $this->context();
        $student = $c['enrollment']->student;
        $student->first_name = '<script>alert(1)</script>';
        $student->save();
        $r = $this->report($c);
        $before = $r->getAttributes();
        $this->actingAs($c['admin'])->get('/report-cards/'.$r->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false);
        $this->assertSame($before, $r->fresh()->getAttributes());
    }

    public function test_absence_report_displays_saved_fail_status_and_blank_rank(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c, ['theory_marks' => null, 'practical_marks' => null, 'is_absent' => true]);
        $svc = app(ResultApprovalService::class);
        $b = $svc->generate($c['admin'], $c['exam'], $s);
        $svc->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        $svc->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);
        $r = StudentResult::first();
        $this->actingAs($c['admin'])->get('/report-cards/'.$r->id)->assertOk()->assertSee('Absent')->assertSee('FAIL')->assertSee('0.00%');
    }

    public function test_missing_configured_logo_is_rejected_before_generation(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $school = $c['admin']->school;
        $school->logo_path = 'missing.png';
        $school->save();
        $this->expectException(ValidationException::class);
        app(ReportCardContext::class)->capture($school);
    }
}

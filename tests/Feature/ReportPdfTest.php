<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportPdf;
use App\Models\ReportPdfRequest;
use App\Models\StudentResult;
use App\Models\User;
use App\Services\ProvisionSchool;
use App\Services\ReportCardPdf;
use App\Services\ReportPdfService;
use App\Services\ResultApprovalService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsMarksContext;
use Tests\Concerns\BuildsResultContext;
use Tests\TestCase;

class ReportPdfTest extends TestCase
{
    use BuildsMarksContext,BuildsResultContext,RefreshDatabase;

    private function published(int $students = 1): array
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        for ($i = 2; $i <= $students; $i++) {
            $e = $this->enroll($c, $i);
            $this->locked($c, ['enrollment_id' => $e->id]);
        }
        $svc = app(ResultApprovalService::class);
        $b = $svc->generate($c['admin'], $c['exam'], $s);
        $svc->transition($c['admin'], $b, ['action' => 'approve', 'version' => 1]);
        $svc->transition($c['admin'], $b, ['action' => 'publish', 'version' => 2]);

        return $c;
    }

    private function filters(array $c, array $extra = []): array
    {
        return array_replace(['academic_year_id' => $c['year']->id, 'exam_id' => $c['exam']->id, 'class_id' => $c['class']->id, 'scope' => 'class'], $extra);
    }

    public function test_individual_download_is_an_actual_a4_pdf(): void
    {
        Storage::fake('local');
        $c = $this->published();
        $r = StudentResult::first();
        $response = $this->actingAs($c['admin'])->get('/report-cards/'.$r->id.'/pdf');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('/Type /Page', $response->getContent());
        $this->assertEmpty(Storage::disk('local')->allFiles('result-pdfs/tmp'));
    }

    public function test_class_request_is_queued_on_dedicated_connection_without_rendering_in_http(): void
    {
        Queue::fake();
        $c = $this->published(2);
        $this->mock(ReportCardPdf::class)->shouldNotReceive('render');
        $this->actingAs($c['admin'])->post('/report-pdfs', $this->filters($c))->assertSessionHasNoErrors();
        $job = ReportPdfRequest::first();
        $this->assertSame('QUEUED', $job->status);
        $this->assertSame(2, $job->student_count);
        Queue::assertPushed(GenerateReportPdf::class, fn ($j) => $j->requestId === $job->id && $j->schoolId === $c['admin']->school_id && $j->connection === 'result-pdfs');
    }

    public function test_worker_renders_multiple_chunks_and_private_download_is_authorized(): void
    {
        Storage::fake('local');
        Queue::fake();
        $c = $this->published(3);
        config(['reports.pdf_chunk_size' => 1]);
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::orderBy('roll_number')->get());
        (new GenerateReportPdf($request->id, $request->school_id))->handle(app(ReportCardPdf::class));
        app(CurrentSchool::class)->set($c['admin']->school);
        $request = $request->fresh();
        $this->assertSame('READY', $request->status);
        $this->assertTrue(Storage::disk('local')->exists($request->path));
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($request->path));
        $parser = new Fpdi;
        $this->assertSame(3, $parser->setSourceFile(StreamReader::createByString(Storage::disk('local')->get($request->path))));
        $parser->cleanUp();
        $this->actingAs($c['admin'])->get('/report-pdfs/'.$request->id.'/download')->assertOk()->assertDownload('report-cards.pdf');
        $path = $request->path;
        (new GenerateReportPdf($request->id, $request->school_id))->handle(app(ReportCardPdf::class));
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->assertSame($path, $request->fresh()->path);
    }

    public function test_selected_request_rejects_empty_outside_cohort_and_duplicate_ids(): void
    {
        Queue::fake();
        $c = $this->published();
        $this->actingAs($c['admin']);
        foreach ([[], [999999], [StudentResult::first()->id, StudentResult::first()->id]] as $ids) {
            $this->post('/report-pdfs', $this->filters($c, ['scope' => 'selected', 'ids' => $ids]))->assertSessionHasErrors();
        }
        $this->assertSame(0, ReportPdfRequest::count());
    }

    public function test_view_only_and_other_creator_cannot_download(): void
    {
        Queue::fake();
        $c = $this->published();
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::all());
        $other = $this->user($c, ['result.view', 'result.print']);
        $this->actingAs($other)->get('/report-pdfs/'.$request->id)->assertForbidden();
        $this->get('/report-pdfs/'.$request->id.'/download')->assertForbidden();
        $viewer = $this->user($c, ['result.view']);
        $this->actingAs($viewer)->post('/report-pdfs', $this->filters($c))->assertForbidden();
        $this->get('/report-cards/'.StudentResult::first()->id.'/pdf')->assertForbidden();
    }

    public function test_expired_pending_and_unsafe_paths_never_download(): void
    {
        Queue::fake();
        $c = $this->published();
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::all());
        $this->actingAs($c['admin'])->get('/report-pdfs/'.$request->id.'/download')->assertStatus(409);
        $request->forceFill(['status' => 'READY', 'finished_at' => now(), 'path' => '../../.env'])->save();
        $this->get('/report-pdfs/'.$request->id.'/download')->assertStatus(409);
        $request->expires_at = now()->subMinute();
        $request->save();
        $this->get('/report-pdfs/'.$request->id.'/download')->assertStatus(410);
    }

    public function test_worker_rechecks_revoked_permissions_and_marks_failed_without_artifact(): void
    {
        Storage::fake('local');
        Queue::fake();
        $c = $this->published();
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::all());
        $c['admin']->is_active = false;
        $c['admin']->save();
        try {
            (new GenerateReportPdf($request->id, $request->school_id))->handle(app(ReportCardPdf::class));
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }app(CurrentSchool::class)->set($c['admin']->school);
        $this->assertSame('FAILED', $request->fresh()->status);
        $this->assertEmpty(Storage::disk('local')->allFiles('result-pdfs'));
    }

    public function test_renderer_failure_is_recorded_and_temp_files_are_removed(): void
    {
        Storage::fake('local');
        Queue::fake();
        $c = $this->published();
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::all());
        $renderer = $this->mock(ReportCardPdf::class);
        $renderer->shouldReceive('render')->once()->andThrow(new \RuntimeException('Render failed'));
        try {
            (new GenerateReportPdf($request->id, $request->school_id))->handle($renderer);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Render failed', $e->getMessage());
        }app(CurrentSchool::class)->set($c['admin']->school);
        $this->assertSame('FAILED', $request->fresh()->status);
        $this->assertEmpty(Storage::disk('local')->allFiles('result-pdfs'));
    }

    public function test_guests_and_cross_school_request_ids_are_denied(): void
    {
        Queue::fake();
        $c = $this->published();
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::all());
        $this->get('/report-pdfs')->assertRedirect('/login');
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other', $other->email);
        $this->actingAs($other->fresh())->get('/report-pdfs/'.$request->id)->assertNotFound();
        $this->get('/report-pdfs')->assertOk()->assertViewHas('requests', fn ($r) => $r->total() === 0);
    }

    public function test_job_status_and_list_pages_show_only_request_owner(): void
    {
        Queue::fake();
        $c = $this->published();
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::all());
        $this->actingAs($c['admin'])->get('/report-pdfs')->assertOk()->assertSee('QUEUED');
        $this->get('/report-pdfs/'.$request->id)->assertOk()->assertSee('Refresh status');
        $request->forceFill(['status' => 'FAILED', 'finished_at' => now()])->save();
        $this->get('/report-pdfs/'.$request->id)->assertOk()->assertSee('could not be prepared');
    }

    public function test_batch_limit_and_expired_cleanup(): void
    {
        Storage::fake('local');
        Queue::fake();
        $c = $this->published(2);
        config(['reports.max_batch_students' => 1]);
        $this->actingAs($c['admin'])->post('/report-pdfs', $this->filters($c))->assertSessionHasErrors('results');
        config(['reports.max_batch_students' => 500]);
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::all());
        $path = 'result-pdfs/'.$request->school_id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, 'dummy');
        $request->forceFill(['status' => 'READY', 'path' => $path, 'finished_at' => now(), 'expires_at' => now()->subMinute()])->save();
        Storage::disk('local')->put('result-pdfs/tmp/orphan/0.pdf', 'old chunk');
        touch(Storage::disk('local')->path('result-pdfs/tmp/orphan/0.pdf'), now()->subDays(2)->timestamp);
        $this->artisan('results:prune-pdfs')->assertExitCode(0);
        $this->assertFalse(Storage::disk('local')->exists('result-pdfs/tmp/orphan/0.pdf'));
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame(0, ReportPdfRequest::count());
    }

    public function test_captured_webp_logo_is_converted_and_embedded_in_pdf(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $image = imagecreatetruecolor(12, 12);
        ob_start();
        imagewebp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('logo.webp', $bytes);
        $school = $c['admin']->school;
        $school->logo_path = 'logo.webp';
        $school->save();
        $scheme = $this->scheme($c);
        $this->locked($c);
        $svc = app(ResultApprovalService::class);
        $batch = $svc->generate($c['admin'], $c['exam'], $scheme);
        $svc->transition($c['admin'], $batch, ['action' => 'approve', 'version' => 1]);
        $svc->transition($c['admin'], $batch, ['action' => 'publish', 'version' => 2]);
        Storage::disk('local')->delete('logo.webp');
        $response = $this->actingAs($c['admin'])->get('/report-cards/'.StudentResult::first()->id.'/pdf');
        $response->assertOk();
        $this->assertStringContainsString('/Subtype /Image', $response->getContent());
    }

    public function test_worker_does_not_render_expired_request_or_reveal_failure_details(): void
    {
        Queue::fake();
        $c = $this->published();
        $request = app(ReportPdfService::class)->request($c['admin'], StudentResult::all());
        $request->expires_at = now()->subMinute();
        $request->save();
        $renderer = $this->mock(ReportCardPdf::class);
        $renderer->shouldNotReceive('render');
        (new GenerateReportPdf($request->id, $request->school_id))->handle($renderer);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->actingAs($c['admin'])->get('/report-pdfs/'.$request->id)->assertOk()->assertSee('EXPIRED')->assertDontSee('Download PDF');
    }

    public function test_requests_list_pagination_and_selection_order_are_preserved(): void
    {
        Queue::fake();
        $c = $this->published(2);
        $rows = StudentResult::orderByDesc('roll_number')->get();
        for ($i = 0; $i < 23; $i++) {
            app(ReportPdfService::class)->request($c['admin'], $rows);
        }
        $this->assertSame($rows->pluck('id')->all(), ReportPdfRequest::first()->snapshot_ids);
        $this->actingAs($c['admin'])->get('/report-pdfs')->assertOk()->assertViewHas('requests', fn ($r) => $r->total() === 23 && $r->count() === 20);
        $this->get('/report-pdfs?page=2')->assertOk()->assertViewHas('requests', fn ($r) => $r->count() === 3);
    }
}

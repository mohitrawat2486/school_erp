<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\StudentResult;
use App\Models\User;
use App\Services\ClassResultWorkbook;
use App\Services\ProvisionSchool;
use App\Services\ResultApprovalService;
use App\Services\ResultCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarksContext;
use Tests\Concerns\BuildsResultContext;
use Tests\TestCase;

class ClassResultTest extends TestCase
{
    use BuildsMarksContext,BuildsResultContext,RefreshDatabase;

    private function published(bool $many = false): array
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        if ($many) {
            for ($i = 2; $i <= 23; $i++) {
                $e = $this->enroll($c, $i);
                $this->locked($c, ['enrollment_id' => $e->id]);
            }
        }
        $other = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'B']);
        $e = $this->enroll($c, 1, $other);
        $bContext = $c;
        $bContext['section'] = $other;
        $this->locked($bContext, ['enrollment_id' => $e->id, 'theory_marks' => 0, 'practical_marks' => 0]);
        $batch = app(ResultApprovalService::class)->generate($c['admin'], $c['exam'], $s);
        app(ResultApprovalService::class)->transition($c['admin'], $batch, ['action' => 'approve', 'version' => 1]);
        app(ResultApprovalService::class)->transition($c['admin'], $batch, ['action' => 'publish', 'version' => 2]);
        $c['other_section'] = $other;

        return $c;
    }

    private function filters(array $c, array $extra = []): array
    {
        return array_replace(['academic_year_id' => $c['year']->id, 'exam_id' => $c['exam']->id, 'class_id' => $c['class']->id], $extra);
    }

    private function url(array $c, array $extra = []): string
    {
        return '/class-results?'.http_build_query($this->filters($c, $extra));
    }

    public function test_guided_filters_and_class_directory(): void
    {
        $c = $this->published();
        $this->actingAs($c['admin'])->get('/class-results')->assertRedirect(route('class-results.select'));
        $chosen = [];
        foreach (['academic_year_id' => $c['year']->id, 'exam_id' => $c['exam']->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id] as $field => $id) {
            $this->get('/class-results/select?'.http_build_query($chosen))->assertOk()->assertViewHas('field', $field);
            $chosen[$field] = $id;
        }
        $this->get($this->url($c))->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 2)->assertSee('85.00')->assertSee('Print Entire Class')->assertSee('Export Excel');
    }

    public function test_section_result_and_name_filters_keep_published_ranks(): void
    {
        $c = $this->published();
        $this->actingAs($c['admin'])->get($this->url($c, ['section_id' => $c['other_section']->id]))->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 1 && $r->first()->result_status === 'FAIL' && $r->first()->rank === null);
        $this->get($this->url($c, ['result_status' => 'PASS']))->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 1 && $r->first()->rank === 1);
        $this->get($this->url($c, ['q' => 'NO SUCH STUDENT']))->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 0);
    }

    public function test_historical_names_and_closed_year_choices_use_only_snapshots(): void
    {
        $c = $this->published();
        $name = $c['year']->name;
        $c['year']->name = 'Changed year';
        $c['year']->status = 'CLOSED';
        $c['year']->save();
        $c['class']->name = 'Changed class';
        $c['class']->save();
        $this->actingAs($c['admin'])->get('/class-results/select')->assertOk()->assertSee($name)->assertDontSee('Changed year');
        $this->get($this->url($c))->assertOk()->assertDontSee('Changed class');
    }

    public function test_wrong_context_and_other_school_filters_are_rejected(): void
    {
        $c = $this->published();
        $this->actingAs($c['admin']);
        foreach (['academic_year_id', 'exam_id', 'class_id', 'section_id'] as $f) {
            $this->get($this->url($c, [$f => 999999]))->assertSessionHasErrors($f);
        }$this->get($this->url($c, ['result_status' => 'UNKNOWN']))->assertSessionHasErrors('result_status');
    }

    public function test_roster_pagination_and_preserved_filter_links(): void
    {
        $c = $this->published(true);
        $this->actingAs($c['admin'])->get($this->url($c, ['section_id' => $c['section']->id]))->assertOk()->assertViewHas('results', fn ($r) => $r->total() === 23 && $r->count() === 20 && str_contains($r->nextPageUrl(), 'section_id='));
        $this->get($this->url($c, ['section_id' => $c['section']->id, 'page' => 2]))->assertOk()->assertViewHas('results', fn ($r) => $r->count() === 3);
    }

    public function test_print_selected_one_and_entire_class_ignore_narrow_search_only_when_requested(): void
    {
        $c = $this->published();
        $id = StudentResult::where('section_id', $c['section']->id)->value('id');
        $this->actingAs($c['admin'])->post('/class-results/print', $this->filters($c, ['scope' => 'selected', 'ids' => [$id]]))->assertOk()->assertViewHas('results', fn ($r) => $r->count() === 1);
        $this->get('/class-results/print?'.http_build_query($this->filters($c, ['scope' => 'selected', 'ids' => [$id]])))->assertOk()->assertSee('Print summary');
        $this->post('/class-results/print', $this->filters($c, ['section_id' => $c['section']->id, 'q' => 'NO MATCH', 'scope' => 'class']))->assertOk()->assertViewHas('results', fn ($r) => $r->count() === 2);
    }

    public function test_print_selected_rejects_empty_duplicate_and_outside_section_ids(): void
    {
        $c = $this->published();
        $id = StudentResult::where('section_id', $c['other_section']->id)->value('id');
        $this->actingAs($c['admin']);
        foreach ([[], [$id], [$id, $id], [999999]] as $ids) {
            $this->post('/class-results/print', $this->filters($c, ['section_id' => $c['section']->id, 'scope' => 'selected', 'ids' => $ids]))->assertSessionHasErrors();
        }
    }

    public function test_view_only_user_cannot_print_or_export(): void
    {
        $c = $this->published();
        $u = $this->user($c, ['result.view']);
        $this->actingAs($u)->get($this->url($c))->assertOk()->assertDontSee('Print Selected')->assertDontSee('Export Excel');
        $this->post('/class-results/print', $this->filters($c, ['scope' => 'class']))->assertForbidden();
        $this->get('/class-results/export?'.http_build_query($this->filters($c)))->assertForbidden();
    }

    public function test_excel_download_has_valid_package_numeric_and_safe_text_cells(): void
    {
        $c = $this->published();
        $this->actingAs($c['admin']);
        $response = $this->get('/class-results/export?'.http_build_query($this->filters($c)));
        $response->assertOk()->assertDownload('class-results.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml'] as $file) {
            $xml = $zip->getFromName($file);
            $this->assertNotFalse($xml);
            $this->assertNotFalse(simplexml_load_string($xml));
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<v>85.00</v>', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $zip->close();
        unlink($path);
    }

    public function test_workbook_strings_cannot_become_formulas_or_invalid_xml(): void
    {
        $c = $this->published();
        $row = StudentResult::first();
        $row->student_name = '=SUM(A1:A9) & <script>';
        $path = app(ClassResultWorkbook::class)->create([$row]);
        try {
            $z = new \ZipArchive;
            $z->open($path);
            $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('t="inlineStr"', $sheet);
            $this->assertStringContainsString('=SUM(A1:A9) &amp; &lt;script&gt;', $sheet);
            $this->assertStringNotContainsString('<f>', $sheet);
            $this->assertNotFalse(simplexml_load_string($sheet));
            $z->close();
        } finally {
            unlink($path);
        }
    }

    public function test_guests_unprivileged_and_other_school_have_no_access(): void
    {
        $c = $this->published();
        $this->get('/class-results')->assertRedirect('/login');
        $none = $this->user($c, []);
        $this->actingAs($none)->get('/class-results/select')->assertForbidden();
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other', $other->email);
        $other = $other->fresh();
        $this->actingAs($other)->get('/class-results/select')->assertOk()->assertViewHas('records', fn ($r) => $r->total() === 0);
        $this->get($this->url($c))->assertSessionHasErrors();
    }

    public function test_outputs_do_not_mutate_snapshots_or_recalculate_results(): void
    {
        $c = $this->published();
        $before = StudentResult::first()->getAttributes();
        $this->mock(ResultCalculationService::class)->shouldNotReceive('calculateExam');
        $this->actingAs($c['admin'])->get($this->url($c))->assertOk();
        $this->post('/class-results/print', $this->filters($c, ['scope' => 'class']))->assertOk();
        $this->assertSame($before, StudentResult::first()->getAttributes());
    }

    public function test_picker_rejects_out_of_order_context_and_oversize_selection(): void
    {
        $c = $this->published();
        $this->actingAs($c['admin'])->get('/class-results/select?class_id='.$c['class']->id)->assertSessionHasErrors('class_id');
        $this->post('/class-results/print', $this->filters($c, ['scope' => 'selected', 'ids' => range(1, 1001)]))->assertSessionHasErrors('ids');
    }

    public function test_all_class_print_and_filtered_export_include_later_pages(): void
    {
        $c = $this->published(true);
        $this->actingAs($c['admin'])->post('/class-results/print', $this->filters($c, ['scope' => 'class', 'section_id' => $c['section']->id]))->assertOk()->assertViewHas('results', fn ($r) => $r->count() === 24);
        $this->post('/class-results/print', $this->filters($c, ['scope' => 'filtered', 'section_id' => $c['section']->id]))->assertOk()->assertViewHas('results', fn ($r) => $r->count() === 23);
        $response = $this->get('/class-results/export?'.http_build_query($this->filters($c, ['section_id' => $c['section']->id])));
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertCount(24, $xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheetData->row);
        $zip->close();
        unlink($path);
    }

    public function test_detailed_excel_keeps_snapshot_identity_marks_and_numeric_formats(): void
    {
        $this->published();
        $rows = StudentResult::orderBy('id')->get();
        $rows[0]->report_context = ['admission_number' => '000148'];
        $rows[1]->report_context = null;
        $path = app(ClassResultWorkbook::class)->create($rows);
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml', 'xl/styles.xml'] as $part) {
                $this->assertNotFalse(simplexml_load_string($zip->getFromName($part)));
            }
            $main = $zip->getFromName('xl/worksheets/sheet1.xml');
            $detail = $zip->getFromName('xl/worksheets/sheet2.xml');
            $this->assertStringContainsString('Admission Number', $main);
            $this->assertStringContainsString('(Marks)', $main);
            $this->assertStringContainsString('r="B2" s="4" t="inlineStr"><is><t xml:space="preserve">000148', $main);
            $this->assertStringContainsString('Not recorded at publication', $main);
            $this->assertStringContainsString('<v>0.00</v>', $main);
            $this->assertStringContainsString('Theory Marks', $detail);
            $this->assertStringContainsString('Practical Marks', $detail);
            $this->assertStringContainsString('Passing Marks', $detail);
            $this->assertStringContainsString('xSplit="3"', $main);
            $this->assertStringContainsString('autoFilter', $detail);
            $this->assertStringContainsString('0.00&quot;%&quot;', $zip->getFromName('xl/styles.xml'));
            $this->assertStringContainsString('Subject Results', $zip->getFromName('xl/workbook.xml'));
            $this->assertStringNotContainsString('<f>', $detail);
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_detailed_excel_rejects_excess_subject_rows_without_partial_download(): void
    {
        $c = $this->published();
        config(['exports.max_subject_rows' => 1]);
        $this->actingAs($c['admin'])->getJson('/class-results/export?'.http_build_query($this->filters($c)))
            ->assertUnprocessable()->assertJsonValidationErrors('results');
    }

    public function test_detailed_excel_rejects_excess_subject_columns(): void
    {
        $c = $this->published();
        config(['exports.max_subject_columns' => 0]);
        $this->actingAs($c['admin'])->getJson('/class-results/export?'.http_build_query($this->filters($c)))
            ->assertUnprocessable()->assertJsonValidationErrors('results');
    }

    public function test_excel_distinguishes_absence_from_zero(): void
    {
        $c = $this->context();
        $scheme = $this->scheme($c);
        $this->locked($c, ['theory_marks' => null, 'practical_marks' => null, 'is_absent' => true]);
        $service = app(ResultApprovalService::class);
        $batch = $service->generate($c['admin'], $c['exam'], $scheme);
        $service->transition($c['admin'], $batch, ['action' => 'approve', 'version' => 1]);
        $service->transition($c['admin'], $batch, ['action' => 'publish', 'version' => 2]);
        $path = app(ClassResultWorkbook::class)->create(StudentResult::all());
        try {
            $zip = new \ZipArchive;
            $zip->open($path);
            $this->assertStringContainsString('ABSENT', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $this->assertStringContainsString('>Yes<', $zip->getFromName('xl/worksheets/sheet2.xml'));
            $zip->close();
        } finally {
            unlink($path);
        }
    }
}

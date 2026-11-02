<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\TeacherAssignmentService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class ClassDirectoryTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $c['class']->update(['name' => 'Class 1', 'code' => 'CLASS-1']);
        $c['section']->update(['name' => 'A']);
        $c['allocation']->subject->update(['name' => 'English', 'code' => 'ENG']);
        $c['b'] = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'B']);
        $c['teacher'] = TeacherProfile::factory()->create();
        $c['teacher']->employee->update(['first_name' => 'Pooja', 'middle_name' => null, 'last_name' => 'Rawat']);
        $c['math'] = ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id]);
        $c['math']->subject->update(['name' => 'Mathematics', 'code' => 'MATH']);
        $c['other'] = SchoolClass::factory()->create(['name' => 'Class 2', 'code' => 'CLASS-2']);
        $c['otherSection'] = Section::factory()->create(['class_id' => $c['other']->id, 'name' => 'A']);
        $this->assign($c, $c['class']->id, $c['section']->id);
        $this->assign($c, $c['other']->id, null);
        $this->assign($c, $c['class']->id, $c['section']->id, $c['allocation']->subject_id);
        $this->assign($c, $c['class']->id, null, $c['math']->subject_id);

        return $c;
    }

    private function assign(array $c, int $class, ?int $section, ?int $subject = null): void
    {
        app(TeacherAssignmentService::class)->create($c['admin'], ['academic_year_id' => $c['year']->id, 'teacher_id' => $c['teacher']->id, 'class_id' => $class, 'section_id' => $section, 'subject_id' => $subject, 'assignment_type' => $subject ? 'SUBJECT_TEACHER' : 'CLASS_TEACHER']);
    }

    public function test_section_roster_and_summary_match_real_current_year_data(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/academics/classes')->assertOk()->assertViewHas('mode', 'sections')
            ->assertViewHas('totals', fn ($totals) => $totals === ['classes' => 2, 'sections' => 3, 'students' => 1, 'teachers' => 2])
            ->assertViewHas('coverage', ['assigned' => 2, 'pending' => 1])->assertViewHas('records', fn ($rows) => $rows->total() === 3)
            ->assertSee('Pooja Rawat')->assertSee('CLASS-1-A')->assertSee('English')->assertSee('Mathematics')->assertSee('Pending')->assertSee('Not assigned')->assertDontSee('Bulk setup')->assertDontSee('data-class-bulk', false)->assertSee('How it works');
    }

    public function test_teacher_subject_badges_only_include_what_the_class_teacher_teaches_in_that_section(): void
    {
        $c = $this->workspace();
        $otherTeacher = TeacherProfile::factory()->create();
        $extra = ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id]);
        app(TeacherAssignmentService::class)->create($c['admin'], ['academic_year_id' => $c['year']->id, 'teacher_id' => $otherTeacher->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'subject_id' => $extra->subject_id, 'assignment_type' => 'SUBJECT_TEACHER']);
        $response = $this->actingAs($c['admin'])->get('/academics/classes?class_id='.$c['class']->id)->assertOk();
        $rows = $response->viewData('rows');
        $this->assertSame(['English', 'Mathematics'], $rows[0]['taught']->pluck('name')->all());
        $this->assertCount(3, $rows[0]['subjects']);
        $this->assertEmpty($rows[1]['taught']);
    }

    public function test_class_subject_and_readiness_filters_work_in_both_views(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/academics/classes?class_id='.$c['class']->id)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 2);
        $this->get('/academics/classes?subject_id='.$c['math']->subject_id)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 2);
        $this->get('/academics/classes?status=PENDING')->assertOk()->assertViewHas('records', fn ($rows) => $rows->pluck('id')->all() === [$c['b']->id]);
        $this->get('/academics/classes?status=ACTIVE')->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 2);
        $this->get('/academics/classes?view=classes&status=PENDING')->assertOk()->assertViewHas('classes', fn ($rows) => $rows->pluck('id')->all() === [$c['class']->id]);
        $this->get('/academics/classes?view=classes&status=ACTIVE')->assertOk()->assertViewHas('classes', fn ($rows) => $rows->pluck('id')->all() === [$c['other']->id]);
    }

    public function test_search_includes_class_section_reference_teacher_and_subject(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin']);
        foreach (['Class 1' => 2, 'CLASS-1-A' => 1, 'Pooja' => 3, 'English' => 2, 'MATH' => 2, 'missing' => 0, '0' => 0] as $term => $count) {
            $this->get('/academics/classes?q='.urlencode((string) $term))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === $count);
        }
        $this->get('/academics/classes?'.http_build_query(['tables' => ['page' => ['search' => 'CLASS-1-A', 'length' => 10]]]))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1);
    }

    public function test_selected_year_changes_students_subjects_and_assignment_coverage(): void
    {
        $c = $this->workspace();
        $year = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $year->forceFill(['status' => 'ACTIVE'])->save();
        $this->actingAs($c['admin'])->get('/academics/classes?academic_year_id='.$year->id)->assertOk()->assertViewHas('year', fn ($record) => $record->id === $year->id)
            ->assertViewHas('totals', fn ($totals) => $totals['students'] === 0 && $totals['teachers'] === 0)
            ->assertViewHas('rows', fn ($rows) => $rows->every(fn ($row) => $row['subjects']->isEmpty() && $row['teacher'] === null && $row['status'] === 'PENDING'));
    }

    public function test_no_current_year_shows_guidance_and_does_not_use_historical_counts(): void
    {
        $c = $this->workspace();
        $c['year']->forceFill(['is_current' => false])->save();
        $this->actingAs($c['admin'])->get('/academics/classes')->assertOk()->assertSee('Choose an academic year')->assertSee('No year selected')
            ->assertViewHas('totals', fn ($totals) => $totals['students'] === null && $totals['teachers'] === null);
        $this->get('/academics/classes?status=PENDING')->assertSessionHasErrors('status');
    }

    public function test_inactive_sections_stay_hidden_and_inactive_classes_are_available_in_class_view(): void
    {
        $c = $this->workspace();
        Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'PRIVATE INACTIVE', 'status' => 'INACTIVE']);
        $class = SchoolClass::factory()->create(['name' => 'Closed class', 'status' => 'INACTIVE']);
        Section::factory()->create(['class_id' => $class->id, 'name' => 'PRIVATE PARENT INACTIVE']);
        $this->actingAs($c['admin'])->get('/academics/classes')->assertOk()->assertDontSee('PRIVATE INACTIVE')->assertDontSee('PRIVATE PARENT INACTIVE')->assertViewHas('records', fn ($rows) => $rows->total() === 3);
        $this->get('/academics/classes?view=classes&status=INACTIVE')->assertOk()->assertViewHas('classes', fn ($rows) => $rows->pluck('id')->all() === [$class->id]);
    }

    public function test_permissions_hide_restricted_counts_teachers_subjects_and_edit_controls(): void
    {
        $c = $this->workspace();
        $reader = $this->user($c, ['class.view']);
        $this->actingAs($reader)->get('/academics/classes')->assertOk()->assertViewHas('mode', 'classes')->assertDontSee('Pooja Rawat')->assertDontSee('English')->assertDontSee('Enrolled students')->assertDontSee('Bulk setup')->assertDontSee('Add class')->assertDontSee('name="subject_id"', false);
        $this->get('/academics/classes?view=sections')->assertSessionHasErrors('view');
        $this->get('/academics/classes?subject_id='.$c['math']->subject_id)->assertSessionHasErrors('subject_id');
        $this->get('/academics/classes?status=PENDING')->assertSessionHasErrors('status');
    }

    public function test_csv_exports_all_matching_rows_with_the_same_permissions_and_filters(): void
    {
        $c = $this->workspace();
        $response = $this->actingAs($c['admin'])->get('/academics/classes/export?class_id='.$c['class']->id)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('CLASS-1-A', $csv);
        $this->assertStringContainsString('CLASS-1-B', $csv);
        $this->assertStringContainsString('Pooja Rawat', $csv);
        $this->assertStringNotContainsString('CLASS-2-A', $csv);
        $this->assertStringNotContainsString('aadhaar', $csv);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->actingAs($this->user($c, ['class.view']));
        $readerCsv = $this->get('/academics/classes/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Pooja', $readerCsv);
        $this->assertStringNotContainsString('Enrolled students', $readerCsv);
        $this->assertStringNotContainsString('English', $readerCsv);
    }

    public function test_csv_protects_spreadsheet_cells_and_is_not_limited_to_one_page(): void
    {
        $c = $this->workspace();
        $c['class']->update(['name' => '=HYPERLINK("example")']);
        Section::factory()->count(23)->sequence(fn ($sequence) => ['class_id' => $c['class']->id, 'name' => 'X'.$sequence->index])->create();
        $csv = $this->actingAs($c['admin'])->get('/academics/classes/export?class_id='.$c['class']->id)->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('CLASS-1-X22', $csv);
    }

    public function test_pagination_keeps_filters_and_lengths_are_bounded(): void
    {
        $c = $this->workspace();
        Section::factory()->count(23)->sequence(fn ($sequence) => ['class_id' => $c['class']->id, 'name' => 'Z'.$sequence->index])->create();
        $this->actingAs($c['admin'])->get('/academics/classes?class_id='.$c['class']->id)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 25 && $rows->count() === 20 && str_contains($rows->url(2), 'class_id='));
        $this->get('/academics/classes?'.http_build_query(['class_id' => $c['class']->id, 'page' => 2, 'tables' => ['page' => ['length' => 10]]]))->assertOk()->assertViewHas('records', fn ($rows) => $rows->count() === 10 && $rows->currentPage() === 2);
        $this->get('/academics/classes?'.http_build_query(['tables' => ['page' => ['length' => 999]]]))->assertSessionHasErrors('tables.page.length');
    }

    public function test_invalid_filters_and_foreign_context_are_rejected(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin']);
        foreach (['view' => 'invalid', 'status' => 'invalid', 'class_id' => 99999999, 'subject_id' => 99999999, 'academic_year_id' => 99999999] as $key => $value) {
            $this->get('/academics/classes?'.http_build_query([$key => $value]))->assertSessionHasErrors($key);
        }
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        $class = SchoolClass::factory()->create(['name' => 'FOREIGN PRIVATE']);
        $subject = Subject::factory()->create(['name' => 'FOREIGN SUBJECT']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->get('/academics/classes')->assertOk()->assertDontSee('FOREIGN PRIVATE')->assertDontSee('FOREIGN SUBJECT');
        $this->get('/academics/classes?class_id='.$class->id)->assertSessionHasErrors('class_id');
        $this->get('/academics/classes?subject_id='.$subject->id)->assertSessionHasErrors('subject_id');
    }

    public function test_assign_action_keeps_the_selected_year_and_is_absent_for_closed_years(): void
    {
        $c = $this->workspace();
        $year = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $year->forceFill(['status' => 'ACTIVE'])->save();
        $response = $this->actingAs($c['admin'])->get('/academics/classes?academic_year_id='.$year->id)->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//tbody//a[contains(@aria-label,"Assign teachers")]');
        $this->assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            $this->assertStringContainsString('academic_year_id='.$year->id, $link->getAttribute('href'));
        }
        $year->forceFill(['status' => 'CLOSED'])->save();
        $this->get('/academics/classes?academic_year_id='.$year->id)->assertOk()->assertDontSee('aria-label="Assign teachers', false);
    }

    public function test_class_export_columns_match_visible_fields_and_no_year_values_are_blank(): void
    {
        $c = $this->workspace();
        $csv = $this->actingAs($c['admin'])->get('/academics/classes/export?view=classes')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Class teacher subjects', strtok($csv, "\n"));
        app(CurrentSchool::class)->set($c['admin']->school);
        $c['year']->forceFill(['is_current' => false])->save();
        $csv = $this->get('/academics/classes/export?class_id='.$c['class']->id)->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', explode("\n", trim($csv)));
        $this->assertSame('', $lines[1][3]);
        $this->assertSame('', $lines[1][6]);
    }
}

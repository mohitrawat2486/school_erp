<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\TeacherAssignmentService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class SectionDirectoryTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $c['class']->update(['name' => 'Class 1', 'code' => 'CLASS-1']);
        $c['section']->update(['name' => 'A']);
        $c['b'] = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'B']);
        $c['inactive'] = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'C', 'status' => 'INACTIVE']);
        $c['other'] = SchoolClass::factory()->create(['name' => 'Class 2', 'code' => 'CLASS-2']);
        $c['otherSection'] = Section::factory()->create(['class_id' => $c['other']->id, 'name' => 'A']);
        $c['teacher'] = TeacherProfile::factory()->create();
        $c['teacher']->employee->update(['first_name' => 'Pooja', 'middle_name' => null, 'last_name' => 'Rawat']);
        foreach ([[$c['class']->id, $c['section']->id], [$c['other']->id, null]] as [$class, $section]) {
            app(TeacherAssignmentService::class)->create($c['admin'], ['academic_year_id' => $c['year']->id, 'teacher_id' => $c['teacher']->id, 'class_id' => $class, 'section_id' => $section, 'assignment_type' => 'CLASS_TEACHER']);
        }

        return $c;
    }

    public function test_overview_includes_inactive_sections_and_class_wide_teacher_coverage(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/academics/sections')->assertOk()
            ->assertViewHas('totals', ['sections' => 4, 'active' => 3, 'inactive' => 1, 'pending' => 1])
            ->assertViewHas('sections', fn ($sections) => $sections->total() === 4)
            ->assertViewHas('rows', fn ($rows) => $rows->where('teacher.id', $c['teacher']->id)->count() === 2 && $rows->where('status', 'PENDING')->count() === 1)
            ->assertSee('Pooja Rawat')->assertSee('CLASS-1-C')->assertSee('How it works')->assertSee('Section reference')->assertDontSee('Bulk create');
    }

    public function test_status_class_and_teacher_search_filters_use_the_same_scope(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin']);
        foreach (['status=ACTIVE' => 3, 'status=INACTIVE' => 1, 'status=PENDING' => 1, 'class_id='.$c['class']->id => 3, 'q=CLASS-1-B' => 1, 'q=Pooja' => 2, 'q=0' => 0] as $query => $count) {
            $this->get('/academics/sections?'.$query)->assertOk()->assertViewHas('sections', fn ($sections) => $sections->total() === $count);
        }
        $this->get('/academics/sections?class_id='.$c['class']->id.'&q=Pooja')->assertOk()->assertViewHas('sections', fn ($sections) => $sections->pluck('id')->all() === [$c['section']->id]);
        $this->get('/academics/sections?'.http_build_query(['tables' => ['page' => ['search' => 'CLASS-1-C', 'length' => 10]]]))->assertOk()->assertViewHas('sections', fn ($sections) => $sections->pluck('id')->all() === [$c['inactive']->id]);
    }

    public function test_selected_year_and_teacher_eligibility_control_pending_and_assignment_links(): void
    {
        $c = $this->workspace();
        $year = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $year->forceFill(['status' => 'ACTIVE'])->save();
        $response = $this->actingAs($c['admin'])->get('/academics/sections?academic_year_id='.$year->id)->assertOk()->assertDontSee('Pooja Rawat')
            ->assertViewHas('totals', fn ($totals) => $totals['pending'] === 3);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $links = (new \DOMXPath($dom))->query('//tbody//a[contains(@aria-label,"Assign class teacher")]');
        $this->assertSame(3, $links->length);
        foreach ($links as $link) {
            $this->assertStringContainsString('academic_year_id='.$year->id, $link->getAttribute('href'));
            $this->assertStringContainsString('assignment_type=CLASS_TEACHER', $link->getAttribute('href'));
        }
        $year->forceFill(['status' => 'CLOSED'])->save();
        $this->get('/academics/sections?academic_year_id='.$year->id)->assertOk()->assertDontSee('aria-label="Assign class teacher', false);
        $c['teacher']->forceFill(['status' => 'INACTIVE'])->save();
        $this->get('/academics/sections?status=PENDING')->assertOk()->assertViewHas('sections', fn ($sections) => $sections->total() === 3)->assertDontSee('Pooja Rawat');
    }

    public function test_no_year_and_section_only_permissions_do_not_expose_teacher_data_or_actions(): void
    {
        $c = $this->workspace();
        $reader = $this->user($c, ['section.view']);
        $this->actingAs($reader)->get('/academics/sections')->assertOk()->assertDontSee('Pooja Rawat')->assertDontSee('Sections without class teacher')->assertDontSee('Add section')->assertDontSee('aria-label="Edit Class', false)->assertDontSee('aria-label="Assign class teacher', false);
        $this->get('/academics/sections?q=Pooja')->assertOk()->assertViewHas('sections', fn ($sections) => $sections->total() === 0);
        $this->get('/academics/sections?status=PENDING')->assertSessionHasErrors('status');
        $csv = $this->get('/academics/sections/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Pooja', $csv);
        $this->assertStringNotContainsString('Class teacher', $csv);
        app(CurrentSchool::class)->set($c['admin']->school);
        $c['year']->forceFill(['is_current' => false])->save();
        $this->actingAs($c['admin'])->get('/academics/sections')->assertOk()->assertSee('No year selected')->assertViewHas('totals', fn ($totals) => $totals['pending'] === null);
        $this->get('/academics/sections?status=PENDING')->assertSessionHasErrors('status');
    }

    public function test_assign_keeps_the_section_when_choosing_a_teacher(): void
    {
        $c = $this->workspace();
        $selection = ['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'section_id' => $c['b']->id, 'scope' => 'SECTION', 'assignment_type' => 'CLASS_TEACHER'];
        $this->actingAs($c['admin'])->get('/academics/teacher-assignments/create?'.http_build_query($selection))->assertOk()
            ->assertViewIs('assignments.select')->assertViewHas('step', 'teacher')->assertViewHas('selection', fn ($values) => $values['section_id'] === $c['b']->id);
        $this->get('/academics/teacher-assignments/create?'.http_build_query($selection + ['teacher_id' => $c['teacher']->id]))->assertOk()
            ->assertViewIs('assignments.form')->assertViewHas('section', fn ($section) => $section->id === $c['b']->id);
        $this->get('/academics/teacher-assignments/create?'.http_build_query(array_replace($selection, ['section_id' => $c['otherSection']->id])))->assertNotFound();
        $this->get('/academics/teacher-assignments/create?'.http_build_query(array_replace($selection, ['section_id' => $c['inactive']->id])))->assertNotFound();
    }

    public function test_export_keeps_filters_exports_all_pages_and_escapes_spreadsheet_formulas(): void
    {
        $c = $this->workspace();
        $c['class']->update(['name' => '=PRIVATE()']);
        Section::factory()->count(23)->sequence(fn ($sequence) => ['class_id' => $c['class']->id, 'name' => 'X'.$sequence->index])->create();
        $csv = $this->actingAs($c['admin'])->get('/academics/sections/export?class_id='.$c['class']->id)->assertOk()->assertDownload('sections.csv')->streamedContent();
        $this->assertStringContainsString("'=PRIVATE()", $csv);
        $this->assertStringContainsString('CLASS-1-X22', $csv);
        $this->assertStringContainsString('Pooja Rawat', $csv);
        $this->assertStringNotContainsString('CLASS-2', $csv);
        $csv = $this->get('/academics/sections/export?status=INACTIVE')->assertOk()->streamedContent();
        $this->assertStringContainsString('CLASS-1-C', $csv);
        $this->assertStringNotContainsString('CLASS-1-A', $csv);
    }

    public function test_filters_are_school_scoped_and_pagination_preserves_them(): void
    {
        $c = $this->workspace();
        Section::factory()->count(23)->sequence(fn ($sequence) => ['class_id' => $c['class']->id, 'name' => 'Z'.$sequence->index])->create();
        $this->actingAs($c['admin'])->get('/academics/sections?class_id='.$c['class']->id)->assertOk()->assertViewHas('sections', fn ($sections) => $sections->total() === 26 && $sections->count() === 20 && str_contains($sections->url(2), 'class_id='));
        $this->get('/academics/sections?'.http_build_query(['class_id' => $c['class']->id, 'page' => 2, 'tables' => ['page' => ['length' => 10]]]))->assertOk()->assertViewHas('sections', fn ($sections) => $sections->count() === 10 && $sections->currentPage() === 2);
        foreach (['status' => 'INVALID', 'tables[page][length]' => 999, 'q' => str_repeat('x', 101)] as $key => $value) {
            $this->get('/academics/sections?'.$key.'='.urlencode($value))->assertSessionHasErrors(str_replace(['[', ']'], ['.', ''], $key));
        }
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        $class = SchoolClass::factory()->create(['name' => 'FOREIGN PRIVATE']);
        Section::factory()->create(['class_id' => $class->id, 'name' => 'FOREIGN SECTION']);
        $year = AcademicYear::create(['name' => 'Foreign year', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->get('/academics/sections')->assertOk()->assertDontSee('FOREIGN PRIVATE')->assertDontSee('FOREIGN SECTION');
        $this->get('/academics/sections?class_id='.$class->id)->assertSessionHasErrors('class_id');
        $this->get('/academics/sections/export?academic_year_id='.$year->id)->assertSessionHasErrors('academic_year_id');
        $this->actingAs($this->user($c, []))->get('/academics/sections/export')->assertForbidden();
    }
}

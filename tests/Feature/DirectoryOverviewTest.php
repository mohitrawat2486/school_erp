<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class DirectoryOverviewTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $c['class']->update(['name' => 'Class 1']);
        $c['section']->update(['name' => 'A']);
        $c['enrollment']->student->update(['first_name' => 'Anika', 'middle_name' => null, 'last_name' => 'Sharma', 'aadhaar_number' => '234567890123']);
        $c['subject'] = $c['allocation']->subject;
        $c['subject']->update(['name' => 'Mathematics', 'code' => 'MATH']);
        $c['unused'] = Subject::factory()->create(['name' => 'Music', 'type' => 'OPTIONAL']);
        $c['inactiveSubject'] = Subject::factory()->create(['name' => 'Old curriculum', 'status' => 'INACTIVE']);
        $c['unenrolled'] = Student::factory()->create(['first_name' => 'Ravi']);
        $c['inactiveStudent'] = Student::factory()->create(['first_name' => 'Former', 'status' => 'INACTIVE']);

        return $c;
    }

    public function test_subject_overview_counts_all_school_subjects_and_current_year_class_use(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/academics/subjects')->assertOk()
            ->assertViewHas('totals', ['subjects' => 3, 'active' => 2, 'inactive' => 1, 'unassigned' => 1])
            ->assertSee('data-external-search', false)->assertSee('How it works')->assertSee('By class')->assertSee('Subjects without classes');
        $this->get('/academics/subjects?status=INACTIVE')->assertOk()->assertViewHas('subjects', fn ($subjects) => $subjects->pluck('id')->all() === [$c['inactiveSubject']->id])
            ->assertViewHas('totals', fn ($totals) => $totals['subjects'] === 3);
        $this->get('/academics/subjects?'.http_build_query(['type' => 'OPTIONAL', 'tables' => ['page' => ['search' => 'Music', 'length' => 10]]]))->assertOk()->assertViewHas('subjects', fn ($subjects) => $subjects->pluck('id')->all() === [$c['unused']->id]);
    }

    public function test_student_overview_and_rows_show_only_current_active_placement(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/students')->assertOk()
            ->assertViewHas('totals', ['students' => 3, 'active' => 2, 'inactive' => 1, 'enrolled' => 1])
            ->assertViewHas('students', function ($students) use ($c) {
                $student = $students->firstWhere('id', $c['enrollment']->student_id);

                return $student->relationLoaded('enrollments') && $student->enrollments->first()->section->name === 'A';
            })->assertSee('Anika Sharma')->assertSee('Not enrolled')->assertSee('How it works')->assertSee('data-external-search', false)->assertDontSee('234567890123');
        $c['enrollment']->forceFill(['status' => 'TRANSFERRED'])->save();
        $this->get('/students')->assertOk()->assertViewHas('totals', fn ($totals) => $totals['enrolled'] === 0)
            ->assertViewHas('students', fn ($students) => $students->every(fn ($student) => $student->enrollments->isEmpty()));
    }

    public function test_subject_export_uses_the_same_search_and_filters_and_exports_every_page(): void
    {
        $c = $this->workspace();
        $c['unused']->update(['name' => '=FORMULA()']);
        Subject::factory()->count(23)->sequence(fn ($sequence) => ['name' => 'Music '.$sequence->index, 'type' => 'OPTIONAL'])->create();
        $csv = $this->actingAs($c['admin'])->get('/academics/subjects/export?type=OPTIONAL')->assertOk()->assertDownload('subjects.csv')->streamedContent();
        $this->assertStringContainsString("'=FORMULA()", $csv);
        $this->assertStringContainsString('Music 22', $csv);
        $this->assertStringNotContainsString('Mathematics', $csv);
        $csv = $this->get('/academics/subjects/export?'.http_build_query(['tables' => ['page' => ['search' => 'MATH', 'length' => 10]]]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Mathematics', $csv);
        $this->assertStringNotContainsString('Music', $csv);
        $this->assertStringContainsString('2026-27', $csv);
    }

    public function test_student_export_filters_by_class_section_status_and_search_without_private_details(): void
    {
        $c = $this->workspace();
        $csv = $this->actingAs($c['admin'])->get('/students/export?'.http_build_query(['class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'status' => 'ACTIVE', 'tables' => ['page' => ['search' => 'Anika', 'length' => 10]]]))->assertOk()->assertDownload('students.csv')->streamedContent();
        $this->assertStringContainsString('Anika Sharma', $csv);
        $this->assertStringContainsString('Class 1', $csv);
        $this->assertStringContainsString('2026-27', $csv);
        foreach (['Ravi', 'Former', '234567890123', 'aadhaar', 'guardian', 'date_of_birth', 'address'] as $private) {
            $this->assertStringNotContainsString($private, $csv);
        }
        app(CurrentSchool::class)->set($c['admin']->school);
        $c['unenrolled']->update(['first_name' => '=FORMULA()']);
        Student::factory()->count(23)->sequence(fn ($sequence) => ['first_name' => 'Export '.$sequence->index])->create();
        $csv = $this->get('/students/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=FORMULA()", $csv);
        $this->assertStringContainsString('Export 22', $csv);
    }

    public function test_missing_current_year_keeps_assignment_counts_unknown_and_historical_placement_hidden(): void
    {
        $c = $this->workspace();
        $c['year']->forceFill(['is_current' => false])->save();
        $this->actingAs($c['admin'])->get('/academics/subjects')->assertOk()->assertViewHas('totals', fn ($totals) => $totals['unassigned'] === null);
        $this->get('/students')->assertOk()->assertSee('No year selected')->assertViewHas('totals', fn ($totals) => $totals['enrolled'] === null);
        $csv = $this->get('/students/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Class 1', $csv);
        $this->assertStringNotContainsString('2026-27', $csv);
        $csv = $this->get('/academics/subjects/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('2026-27', $csv);
    }

    public function test_school_boundaries_and_permissions_apply_to_cards_actions_and_exports(): void
    {
        $c = $this->workspace();
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        Subject::factory()->create(['name' => 'FOREIGN SUBJECT']);
        Student::factory()->create(['first_name' => 'FOREIGN STUDENT']);
        $class = SchoolClass::factory()->create();
        $section = Section::factory()->create(['class_id' => $class->id]);
        app(CurrentSchool::class)->set($c['admin']->school);
        $reader = $this->user($c, ['student.view', 'subject.view']);
        $this->actingAs($reader)->get('/students')->assertOk()->assertDontSee('Add student')->assertDontSee('aria-label="Edit Anika', false)->assertDontSee('data-progression-open', false)
            ->assertViewHas('totals', fn ($totals) => $totals['students'] === 3);
        $this->get('/academics/subjects')->assertOk()->assertDontSee('Add subject')->assertDontSee('aria-label="Edit Mathematics', false)->assertViewHas('totals', fn ($totals) => $totals['subjects'] === 3);
        foreach (['/students/export', '/academics/subjects/export'] as $url) {
            $csv = $this->get($url)->assertOk()->streamedContent();
            $this->assertStringNotContainsString('FOREIGN', $csv);
        }
        $this->get('/students/export?class_id='.$class->id.'&section_id='.$section->id)->assertSessionHasErrors(['class_id', 'section_id']);
        foreach (['/students/export', '/academics/subjects/export'] as $url) {
            $this->get($url.'?'.http_build_query(['tables' => ['page' => ['length' => 999]]]))->assertSessionHasErrors('tables.page.length');
        }
        $this->actingAs($this->user($c, []));
        $this->get('/students/export')->assertForbidden();
        $this->get('/academics/subjects/export')->assertForbidden();
    }
}

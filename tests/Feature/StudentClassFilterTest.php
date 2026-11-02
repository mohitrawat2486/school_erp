<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\StudentGuardianService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class StudentClassFilterTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function directory(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $c['class']->update(['name' => 'Class 1']);

        return $c;
    }

    public function test_class_filter_uses_current_active_enrollments_across_all_sections(): void
    {
        $c = $this->directory();
        $sectionB = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'B']);
        $second = $this->enroll($c, 1, $sectionB);
        $inactive = $this->enroll($c, 2);
        $inactive->forceFill(['status' => 'INACTIVE'])->save();
        $withdrawn = $this->enroll($c, 3);
        $withdrawn->forceFill(['status' => 'WITHDRAWN'])->save();
        $transferred = $this->enroll($c, 4);
        $transferred->forceFill(['status' => 'TRANSFERRED'])->save();
        Student::factory()->create();
        $otherClass = SchoolClass::factory()->create(['name' => 'Class 2']);
        $otherSection = Section::factory()->create(['class_id' => $otherClass->id]);
        $other = $this->enroll(array_replace($c, ['class' => $otherClass, 'section' => $otherSection]), 1);
        $oldYear = AcademicYear::create(['name' => '2025-26', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31']);
        $oldYear->forceFill(['status' => 'ACTIVE'])->save();
        $other->student->update(['admission_date' => '2025-04-01']);
        app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => $other->student_id, 'academic_year_id' => $oldYear->id, 'class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'roll_number' => 1, 'joined_at' => '2025-04-01']);

        $expected = [$c['enrollment']->student_id, $second->student_id];
        $response = $this->actingAs($c['admin'])->get('/students?class_id='.$c['class']->id)->assertOk()
            ->assertSee('All classes')->assertSee('Class 1')->assertSee('Class 2')->assertSee('2026-27');
        $this->assertEqualsCanonicalizing($expected, $response->viewData('students')->pluck('id')->all());
        $this->get('/students?'.http_build_query(['class_id' => $c['class']->id, 'section_id' => $sectionB->id]))->assertOk()
            ->assertViewHas('students', fn ($rows) => $rows->pluck('id')->all() === [$second->student_id]);
        $this->get('/students?'.http_build_query(['class_id' => $c['class']->id, 'section_id' => $c['section']->id]))->assertOk()
            ->assertViewHas('students', fn ($rows) => $rows->pluck('id')->all() === [$c['enrollment']->student_id]);
        $this->get('/students')->assertOk()->assertViewHas('students', fn ($rows) => $rows->total() === 7);
        $this->get('/students?class_id='.$otherClass->id)->assertOk()->assertViewHas('students', fn ($rows) => $rows->pluck('id')->all() === [$other->student_id]);
    }

    public function test_class_filter_combines_with_search_status_and_table_pagination(): void
    {
        $c = $this->directory();
        $students = [];
        for ($roll = 2; $roll <= 13; $roll++) {
            $enrollment = $this->enroll($c, $roll);
            $student = $enrollment->student;
            $student->forceFill(['first_name' => 'Filtermatch', 'status' => $roll === 13 ? 'INACTIVE' : 'ACTIVE'])->save();
            $students[] = $student;
        }
        $params = ['class_id' => $c['class']->id, 'section_id' => $c['section']->id, 'status' => 'ACTIVE', 'q' => 'Filtermatch', 'tables' => ['page' => ['length' => 10]]];
        $first = $this->actingAs($c['admin'])->get('/students?'.http_build_query($params))->assertOk()->viewData('students');
        $this->assertSame(11, $first->total());
        $this->assertCount(10, $first);
        parse_str(parse_url($first->url(2), PHP_URL_QUERY), $query);
        $this->assertSame((string) $c['class']->id, $query['class_id']);
        $this->assertSame((string) $c['section']->id, $query['section_id']);
        $this->assertSame('ACTIVE', $query['status']);
        $this->assertSame('Filtermatch', $query['q']);
        $this->get($first->url(2))->assertOk()->assertViewHas('students', fn ($rows) => $rows->count() === 1 && $rows->total() === 11);
        $params['status'] = 'INACTIVE';
        $this->get('/students?'.http_build_query($params))->assertOk()->assertViewHas('students', fn ($rows) => $rows->pluck('id')->all() === [$students[11]->id]);
        $params['status'] = 'ACTIVE';
        $params['q'] = 'NoSuchStudent';
        $this->get('/students?'.http_build_query($params))->assertOk()->assertViewHas('students', fn ($rows) => $rows->isEmpty());
    }

    public function test_missing_current_year_does_not_fall_back_to_historical_enrollments(): void
    {
        $c = $this->directory();
        $c['year']->forceFill(['is_current' => false])->save();
        $this->actingAs($c['admin'])->get('/students?class_id='.$c['class']->id)->assertOk()
            ->assertSee('No current academic year is set.')->assertViewHas('students', fn ($rows) => $rows->total() === 0);
        $this->get('/students?'.http_build_query(['class_id' => $c['class']->id, 'section_id' => $c['section']->id]))->assertOk()
            ->assertViewHas('students', fn ($rows) => $rows->total() === 0);
        $this->get('/students')->assertOk()->assertViewHas('students', fn ($rows) => $rows->total() === 1);
    }

    public function test_class_choices_and_input_are_restricted_to_the_current_school(): void
    {
        $c = $this->directory();
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        $foreignClass = SchoolClass::factory()->create(['name' => 'PRIVATE FOREIGN CLASS']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->actingAs($c['admin'])->get('/students')->assertOk()->assertDontSee('PRIVATE FOREIGN CLASS')
            ->assertViewHas('classes', fn ($classes) => $classes->pluck('id')->all() === [$c['class']->id]);
        foreach ([$foreignClass->id, 99999999, 'invalid', ['1']] as $classId) {
            $this->get('/students?'.http_build_query(['class_id' => $classId]))->assertSessionHasErrors('class_id');
        }
        $this->get('/students?class_id=')->assertOk()->assertViewHas('students', fn ($rows) => $rows->total() === 1);
    }

    public function test_section_choices_and_validation_require_an_active_section_in_the_selected_class_and_school(): void
    {
        $c = $this->directory();
        $inactive = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'PRIVATE INACTIVE', 'status' => 'INACTIVE']);
        $otherClass = SchoolClass::factory()->create();
        $otherSection = Section::factory()->create(['class_id' => $otherClass->id, 'name' => 'Other class section']);
        $emptyClass = SchoolClass::factory()->create();
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        $foreignSection = Section::factory()->create(['name' => 'PRIVATE FOREIGN SECTION']);
        app(CurrentSchool::class)->set($c['admin']->school);

        $this->actingAs($c['admin'])->get('/students')->assertOk()->assertSee('Choose a class first')
            ->assertDontSee('PRIVATE INACTIVE')->assertDontSee('PRIVATE FOREIGN SECTION')->assertViewHas('sections', fn ($sections) => $sections->isEmpty());
        $this->get('/students?class_id='.$c['class']->id)->assertOk()->assertSee('All sections')
            ->assertViewHas('sections', fn ($sections) => $sections->pluck('id')->all() === [$c['section']->id]);
        $this->get('/students?class_id='.$emptyClass->id)->assertOk()->assertSee('No active sections');
        foreach ([$inactive->id, $otherSection->id, $foreignSection->id, 99999999, 'invalid', ['1']] as $sectionId) {
            $this->get('/students?'.http_build_query(['class_id' => $c['class']->id, 'section_id' => $sectionId]))->assertSessionHasErrors('section_id');
        }
        $this->get('/students?section_id='.$c['section']->id)->assertSessionHasErrors('section_id');
        $this->get('/students?'.http_build_query(['class_id' => $c['class']->id, 'section_id' => '']))->assertOk()
            ->assertViewHas('students', fn ($rows) => $rows->total() === 1);
    }

    public function test_student_profile_shows_current_placement_and_simple_guardian_lists_with_history(): void
    {
        $c = $this->directory();
        $student = $c['enrollment']->student;
        $current = Guardian::factory()->create(['first_name' => 'Anita', 'last_name' => 'Sharma', 'phone' => '+91 9876543210']);
        $previous = Guardian::factory()->create(['first_name' => 'Ravi', 'last_name' => 'Sharma']);
        $contacts = app(StudentGuardianService::class);
        $contacts->save($c['admin'], $student, $current, ['relationship' => 'MOTHER', 'is_primary' => true, 'is_emergency_contact' => true]);
        $contacts->save($c['admin'], $student, $previous, ['relationship' => 'FATHER', 'is_primary' => false, 'is_emergency_contact' => false]);
        $contacts->unlink($c['admin'], $student, $previous);
        $response = $this->actingAs($c['admin'])->get('/students/'.$student->id)->assertOk()
            ->assertSee('Class 1')->assertSee('Section '.$c['section']->name)->assertSee('Anita Sharma')->assertSee('Ravi Sharma')
            ->assertSee('Primary contact')->assertSee('Emergency contact')->assertSee('Previous guardian links')->assertSee('Edit guardians')
            ->assertViewHas('currentEnrollment', fn ($enrollment) => $enrollment->id === $c['enrollment']->id);
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $this->assertSame(0, $xpath->query('//*[@data-student-guardian-contacts]//table | //*[@data-student-guardian-contacts]//*[@data-list-table]')->length);
        $this->assertSame(1, $xpath->query('//ul[@aria-label="Current guardian contacts"]/li')->length);
        $this->assertSame(1, $xpath->query('//ul[@aria-label="Previous guardian contacts"]/li')->length);
        $this->assertStringContainsString('tel:+919876543210', $response->getContent());
    }
}

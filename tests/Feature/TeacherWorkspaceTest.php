<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class TeacherWorkspaceTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function workspace(): array
    {
        $c = $this->context();
        $c['year']->forceFill(['is_current' => true])->save();
        $teacher = TeacherProfile::factory()->create(['specialization' => 'Mathematics']);
        $teacher->employee->update(['first_name' => 'Ananya', 'middle_name' => null, 'last_name' => 'Rao']);
        $otherTeacher = TeacherProfile::factory()->create(['specialization' => 'English']);
        $oldYear = AcademicYear::create(['name' => '2025-26', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31']);
        $oldYear->forceFill(['status' => 'CLOSED'])->save();
        $base = ['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'teacher_id' => $teacher->id];
        $classAssignment = TeacherAssignment::factory()->create($base + ['section_id' => $c['section']->id]);
        TeacherAssignment::factory()->create($base + ['section_id' => $c['section']->id, 'subject_id' => $c['allocation']->subject_id, 'class_subject_id' => $c['allocation']->id, 'assignment_type' => 'SUBJECT_TEACHER']);
        TeacherAssignment::factory()->create($base + ['status' => 'INACTIVE']);
        TeacherAssignment::factory()->create(['academic_year_id' => $oldYear->id, 'class_id' => $c['class']->id, 'teacher_id' => $teacher->id]);
        TeacherAssignment::factory()->create(['academic_year_id' => $oldYear->id, 'class_id' => $c['class']->id, 'teacher_id' => $teacher->id, 'section_id' => $c['section']->id, 'status' => 'INACTIVE']);

        return $c + compact('teacher', 'otherTeacher', 'oldYear', 'classAssignment');
    }

    public function test_directory_shows_current_active_counts_and_direct_actions_and_one_sidebar_entry(): void
    {
        $c = $this->workspace();
        $directory = ['status' => 'ACTIVE', 'page' => 1, 'tables' => ['page' => ['length' => 10, 'search' => 'Ananya']]];
        $response = $this->actingAs($c['admin'])->get('/teachers?'.http_build_query($directory))->assertOk()
            ->assertSee('All assignments')->assertSee('2 active')->assertSee('View profile')->assertSee('View assignments for Ananya Rao')
            ->assertDontSee('aria-label="Teacher Assignments" title="Teacher Assignments"', false)
            ->assertViewHas('directory', fn ($state) => $state['tables']['page']['search'] === 'Ananya');
        $profiles = $response->viewData('profiles');
        $this->assertSame(2, $profiles->first()->current_assignments_count);
        $this->assertSame($c['teacher']->id, $profiles->first()->id);
        $this->assertStringContainsString('directory%5Btables%5D%5Bpage%5D%5Bsearch%5D=Ananya', $response->getContent());
        $this->get('/teachers')->assertSee('Not assigned');
    }

    public function test_scoped_assignments_keep_teacher_year_and_directory_while_resetting_filters(): void
    {
        $c = $this->workspace();
        $directory = ['page' => 2, 'tables' => ['page' => ['length' => 10, 'search' => '']]];
        $params = ['teacher_id' => $c['teacher']->id, 'academic_year_id' => $c['year']->id, 'status' => 'ACTIVE', 'directory' => $directory];
        $response = $this->actingAs($c['admin'])->get('/academics/teacher-assignments?'.http_build_query($params))->assertOk()
            ->assertSee('Viewing assignments for Ananya Rao')->assertSee('Back to Teachers')->assertSee('View profile')
            ->assertViewHas('assignments', fn ($records) => $records->total() === 2 && $records->every(fn ($row) => $row->teacher_id === $c['teacher']->id));
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $reset = $xpath->query('//*[@data-list-sync="teacher-assignment-reset"]')->item(0)->getAttribute('href');
        parse_str(parse_url($reset, PHP_URL_QUERY), $query);
        $this->assertSame((string) $c['teacher']->id, $query['teacher_id']);
        $this->assertSame((string) $c['year']->id, $query['academic_year_id']);
        $this->assertArrayNotHasKey('status', $query);
        $this->assertSame('2', $query['directory']['page']);
        $this->get('/academics/teacher-assignments?'.http_build_query(array_diff_key($params, ['status' => true])))->assertViewHas('assignments', fn ($records) => $records->total() === 3);
        $this->get('/academics/teacher-assignments?'.http_build_query(array_diff_key($params, ['status' => true, 'academic_year_id' => true])))->assertViewHas('assignments', fn ($records) => $records->total() === 5);
    }

    public function test_preselected_teacher_survives_type_year_class_and_confirmation_without_teacher_picker(): void
    {
        $c = $this->workspace();
        $state = ['directory' => ['tables' => ['page' => ['search' => 'Ananya', 'length' => 10]]], 'list' => ['teacher_id' => $c['teacher']->id, 'academic_year_id' => $c['year']->id, 'status' => 'ACTIVE']];
        $params = ['teacher_id' => $c['teacher']->id, 'academic_year_id' => $c['year']->id] + $state;
        $base = '/academics/teacher-assignments/create';
        $this->actingAs($c['admin'])->get($base.'?'.http_build_query($params))->assertOk()->assertSee('Creating an assignment for')->assertSee('Ananya Rao')->assertViewHas('selection', fn ($selection) => $selection['teacher_id'] === $c['teacher']->id && $selection['directory']['tables']['page']['search'] === 'Ananya');
        $params['assignment_type'] = 'SUBJECT_TEACHER';
        $this->get($base.'?'.http_build_query($params))->assertOk()->assertViewHas('step', 'class')->assertViewHas('selection', fn ($s) => $s['teacher_id'] === $c['teacher']->id && $s['list']['status'] === 'ACTIVE');
        $params['class_id'] = $c['class']->id;
        $this->get($base.'?'.http_build_query($params))->assertOk()->assertViewHas('step', 'section');
        $params += ['scope' => 'SECTION', 'section_id' => $c['section']->id, 'subject_id' => $c['allocation']->subject_id];
        $this->get($base.'?'.http_build_query($params))->assertOk()->assertViewIs('assignments.form')->assertSee('name="directory[tables][page][search]"', false)->assertSee('name="list[teacher_id]"', false);
        // Choosing a year is also allowed before one is preselected.
        unset($params['academic_year_id']);
        $this->get($base.'?'.http_build_query($params))->assertOk()->assertViewHas('step', 'year')->assertViewHas('selection', fn ($s) => $s['teacher_id'] === $c['teacher']->id);
    }

    public function test_assignment_creation_details_and_status_changes_preserve_local_navigation_state(): void
    {
        $c = $this->workspace();
        $state = ['directory' => ['status' => 'ACTIVE', 'page' => 2], 'list' => ['teacher_id' => $c['otherTeacher']->id, 'academic_year_id' => $c['year']->id, 'status' => 'ACTIVE']];
        $data = ['teacher_id' => $c['otherTeacher']->id, 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'assignment_type' => 'SUBJECT_TEACHER', 'subject_id' => $c['allocation']->subject_id] + $state;
        $response = $this->actingAs($c['admin'])->post('/academics/teacher-assignments', $data)->assertRedirect();
        $record = TeacherAssignment::where('teacher_id', $c['otherTeacher']->id)->firstOrFail();
        $this->assertStringContainsString('directory%5Bpage%5D=2', $response->headers->get('Location'));
        $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('workspace', fn ($value) => $value['list']['teacher_id'] == $c['otherTeacher']->id)->assertSee('name="directory[page]"', false);
        $this->post('/academics/teacher-assignments/'.$record->id.'/deactivate', $state)->assertRedirect()->assertSessionHas('status', 'Assignment deactivated.');
        $this->assertSame('INACTIVE', $record->fresh()->status->value);
    }

    public function test_choose_class_retains_teacher_context(): void
    {
        $c = $this->workspace();
        $params = ['teacher_id' => $c['teacher']->id, 'academic_year_id' => $c['year']->id, 'directory' => ['status' => 'ACTIVE']];
        $this->actingAs($c['admin'])->get('/academics/teacher-assignments/filter?'.http_build_query($params))->assertOk()->assertViewHas('selection', fn ($selection) => $selection['teacher_id'] === $c['teacher']->id);
        $params['class_id'] = $c['class']->id;
        $response = $this->get('/academics/teacher-assignments/filter?'.http_build_query($params))->assertRedirect();
        $this->assertStringContainsString('teacher_id='.$c['teacher']->id, $response->headers->get('Location'));
    }

    public function test_permission_combinations_keep_assignment_only_access_without_exposing_counts_to_profile_readers(): void
    {
        $c = $this->workspace();
        $profileReader = $this->user($c, ['teacher.view']);
        $this->actingAs($profileReader)->get('/teachers')->assertOk()->assertViewHas('canViewAssignments', false)->assertDontSee('View assignments for Ananya Rao')->assertDontSee('All assignments')
            ->assertViewHas('profiles', fn ($rows) => $rows->every(fn ($row) => ! array_key_exists('current_assignments_count', $row->getAttributes())));
        $manager = $this->user($c, ['teacher_assignment.view', 'teacher_assignment.manage']);
        $this->actingAs($manager)->get('/academics/teacher-assignments?teacher_id='.$c['teacher']->id)->assertOk()->assertSee('aria-label="Teacher Assignments" title="Teacher Assignments"', false)->assertDontSee('View profile')->assertSee('Add assignment');
        $this->get('/teachers')->assertForbidden();
    }

    public function test_inactive_teachers_and_closed_years_allow_history_but_hide_creation(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->get('/academics/teacher-assignments?teacher_id='.$c['teacher']->id.'&academic_year_id='.$c['oldYear']->id)->assertOk()->assertSee('This academic year is read-only.')->assertDontSee('Add assignment');
        $c['teacher']->forceFill(['status' => 'INACTIVE'])->save();
        $this->get('/academics/teacher-assignments?teacher_id='.$c['teacher']->id)->assertOk()->assertSee('Existing assignments and history')->assertDontSee('Add assignment');
        $this->get('/academics/teacher-assignments/create?teacher_id='.$c['teacher']->id)->assertNotFound();
    }

    public function test_absent_current_year_does_not_display_false_assignment_counts(): void
    {
        $c = $this->workspace();
        $c['year']->forceFill(['is_current' => false])->save();
        $this->actingAs($c['admin'])->get('/teachers')->assertOk()->assertSee('No current year')->assertDontSee('Not assigned')->assertViewHas('year', null);
    }

    public function test_navigation_state_is_bounded_and_cannot_supply_external_return_urls_or_foreign_teachers(): void
    {
        $c = $this->workspace();
        $this->actingAs($c['admin'])->getJson('/teachers/'.$c['teacher']->id.'?'.http_build_query(['directory' => 'https://example.com']))->assertUnprocessable();
        $this->getJson('/academics/teacher-assignments?'.http_build_query(['directory' => ['return_to' => 'https://example.com']]))->assertUnprocessable();
        $this->getJson('/academics/teacher-assignments?'.http_build_query(['list' => ['page' => 0]]))->assertUnprocessable();
        $this->getJson('/academics/teacher-assignments?'.http_build_query(['directory' => ['tables' => ['page' => ['length' => -1]]]]))->assertUnprocessable();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = TeacherProfile::factory()->create();
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->getJson('/academics/teacher-assignments?teacher_id='.$foreign->id)->assertUnprocessable();
        $this->get('/academics/teacher-assignments/create?teacher_id='.$foreign->id)->assertNotFound();
    }

    public function test_profile_edit_and_save_preserve_the_directory_context(): void
    {
        $c = $this->workspace();
        $state = ['directory' => ['status' => 'ACTIVE', 'page' => 2, 'tables' => ['page' => ['search' => 'Ananya', 'length' => 10]]]];
        $this->actingAs($c['admin'])->get('/teachers/'.$c['teacher']->id.'?'.http_build_query($state))->assertOk()->assertSee('View teacher assignments');
        $this->get('/teachers/'.$c['teacher']->id.'/edit?'.http_build_query($state))->assertOk()->assertSee('name="directory[tables][page][search]"', false);
        $response = $this->put('/teachers/'.$c['teacher']->id, ['specialization' => 'Physics'] + $state)->assertRedirect();
        $this->assertStringContainsString('directory%5Bpage%5D=2', $response->headers->get('Location'));
        $this->assertSame('Physics', $c['teacher']->fresh()->specialization);
    }
}

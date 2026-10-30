<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\PromotionBatch;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AcademicYearService;
use App\Services\AuditLogger;
use App\Services\CreateClassWithSetup;
use App\Services\ExamService;
use App\Services\ExamSubjectService;
use App\Services\ExamTermService;
use App\Services\ProvisionSchool;
use App\Services\SectionService;
use App\Services\TeacherAssignmentService;
use App\Services\UpdateClassWithSetup;
use App\Support\ClassSetupState;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class ClassSetupTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    private function setupData(bool $withYear = true): array
    {
        $admin = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('CLASS-SETUP', 'Class Setup School', $admin->email);
        $admin = $admin->fresh();
        app(CurrentSchool::class)->set($admin->school);
        $year = null;
        if ($withYear) {
            $year = app(AcademicYearService::class)->create($admin, ['name' => '2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
            app(AcademicYearService::class)->transition($admin, $year, 'activate');
            $year = $year->fresh();
        }
        $subjects = Subject::factory()->count(2)->create(['status' => 'ACTIVE']);

        return compact('admin', 'year', 'subjects');
    }

    private function payload(array $c): array
    {
        return ['name' => 'Class 2', 'sections' => ['A' => ['enabled' => 1, 'name' => 'A', 'capacity' => 30], 'B' => ['enabled' => 1, 'name' => 'B', 'capacity' => null], 'C' => ['enabled' => 0, 'name' => 'C', 'capacity' => 30], 'custom0' => ['enabled' => 1, 'name' => 'Blue', 'capacity' => 25]], 'academic_year_id' => $c['year']->id, 'subject_ids' => $c['subjects']->modelKeys()];
    }

    public function test_one_save_creates_class_sections_and_current_year_subjects_with_audits(): void
    {
        $c = $this->setupData();
        $this->actingAs($c['admin'])->post('/academics/classes', $this->payload($c) + ['code' => 'FORGED'])->assertRedirect()->assertSessionHas('status', 'Class 2 created with 3 sections and 2 subjects.');
        $class = SchoolClass::firstOrFail();
        $this->assertSame('CLASS-2', $class->code);
        $this->assertSame(2, $class->sort_order);
        $this->assertSame(['A', 'B', 'Blue'], $class->sections()->orderBy('id')->pluck('name')->all());
        $this->assertSame(2, $class->classSubjects()->where('academic_year_id', $c['year']->id)->count());
        $this->assertNull($class->classSubjects()->first()->theory_max_marks);
        $this->assertDatabaseHas('sections', ['class_id' => $class->id, 'name' => 'A', 'capacity' => 30]);
        foreach (['CLASS_CREATED' => 1, 'SECTION_CREATED' => 3, 'CLASS_SUBJECT_CREATED' => 2] as $action => $count) {
            $this->assertSame($count, DB::table('audit_logs')->where('action', $action)->count());
        }
        $this->get('/academics/classes/'.$class->id)->assertOk()->assertSee('Blue')->assertSee('Assign teachers')->assertSee($c['subjects'][0]->name);
        $this->get('/academics/classes')->assertOk()->assertSee('A, B, Blue');
    }

    public function test_class_can_be_created_without_year_sections_or_subjects_and_custom_names_work(): void
    {
        $c = $this->setupData(false);
        $this->actingAs($c['admin'])->get('/academics/classes/create')->assertOk()->assertSee('Set an active current academic year')->assertDontSee('name="code"', false);
        $this->post('/academics/classes', ['name' => 'Nursery', 'sections' => ['custom0' => ['enabled' => 1, 'name' => '', 'capacity' => null]]])->assertRedirect();
        $this->assertDatabaseHas('classes', ['name' => 'Nursery', 'code' => 'NURSERY']);
        $this->assertDatabaseCount('sections', 0);
        $this->assertDatabaseCount('class_subjects', 0);
        $this->post('/academics/classes', ['name' => 'LKG', 'skip_sections' => 1, 'sections' => ['A' => ['enabled' => 1, 'name' => 'A']]])->assertRedirect();
        $this->assertDatabaseCount('sections', 0);
    }

    public function test_bad_sections_subjects_and_capacities_keep_the_whole_class_unsaved(): void
    {
        $c = $this->setupData();
        $payload = $this->payload($c);
        $payload['sections']['B']['name'] = ' a ';
        $payload['sections']['A']['capacity'] = -1;
        $payload['subject_ids'][] = $payload['subject_ids'][0];
        $this->actingAs($c['admin'])->from('/academics/classes/create')->post('/academics/classes', $payload)->assertSessionHasErrors(['sections.A.capacity', 'sections.B.name', 'subject_ids.0']);
        // Keep the browser session cookie across the validation redirect.
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get('/academics/classes/create');
        $response->assertOk()->assertSee('value="Blue"', false)->assertSee('value="-1"', false)->assertSee('role="alert"', false)->assertSee('aria-invalid="true"', false);
        $this->assertDatabaseCount('classes', 0);
        $this->assertDatabaseCount('sections', 0);
        $this->assertDatabaseCount('class_subjects', 0);
    }

    public function test_foreign_and_inactive_subjects_or_wrong_year_are_rejected(): void
    {
        $c = $this->setupData();
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        $subject = Subject::factory()->create(['name' => 'FOREIGN-SUBJECT']);
        $year = AcademicYear::create(['name' => 'Foreign year', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $inactive = Subject::factory()->create(['status' => 'INACTIVE']);
        $this->actingAs($c['admin'])->post('/academics/classes', ['name' => 'Class 2', 'academic_year_id' => $year->id, 'subject_ids' => [$subject->id, $inactive->id]])->assertSessionHasErrors(['academic_year_id', 'subject_ids.0', 'subject_ids.1']);
        $this->assertDatabaseCount('classes', 0);
        $this->get('/academics/classes/create')->assertOk()->assertDontSee('FOREIGN-SUBJECT');
    }

    public function test_subject_assignment_requires_a_current_year_even_for_direct_service_calls(): void
    {
        $c = $this->setupData();
        $c['year']->forceFill(['is_current' => false])->save();
        try {
            app(CreateClassWithSetup::class)->create($c['admin'], ['name' => 'Class 2', 'academic_year_id' => $c['year']->id, 'subject_ids' => $c['subjects']->modelKeys()]);
            $this->fail('A changed current year was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('academic_year_id', $exception->errors());
        }
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_class_only_manager_can_create_but_cannot_inject_sections_or_subjects(): void
    {
        $c = $this->setupData();
        $manager = $this->user($c, ['class.view', 'class.manage']);
        $this->actingAs($manager)->get('/academics/classes/create')->assertOk()->assertDontSee('data-setup-sections', false)->assertDontSee('data-setup-subjects', false);
        $this->post('/academics/classes', ['name' => 'Class 2', 'sections' => [['name' => 'A']]])->assertForbidden();
        $this->post('/academics/classes', ['name' => 'Class 2', 'academic_year_id' => $c['year']->id, 'subject_ids' => $c['subjects']->modelKeys()])->assertForbidden();
        $this->assertDatabaseCount('classes', 0);
        $this->post('/academics/classes', ['name' => 'Class 2'])->assertRedirect();
        $this->assertDatabaseHas('classes', ['name' => 'Class 2', 'code' => 'CLASS-2']);
    }

    public function test_later_failure_rolls_back_class_sections_and_audits(): void
    {
        $c = $this->setupData();
        $auditCount = DB::table('audit_logs')->count();
        $logger = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$arguments) use ($logger) {
            if ($arguments[1] === 'CLASS_SUBJECT_CREATED') {
                throw new \RuntimeException('Cannot record subject mapping');
            }

            return $logger->record(...$arguments);
        });
        try {
            app(CreateClassWithSetup::class)->create($c['admin'], ['name' => 'Class 2', 'sections' => [['name' => 'A', 'capacity' => 30]], 'academic_year_id' => $c['year']->id, 'subject_ids' => $c['subjects']->modelKeys()]);
            $this->fail('Partial setup was saved.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot record subject mapping', $exception->getMessage());
        }
        $this->assertDatabaseCount('classes', 0);
        $this->assertDatabaseCount('sections', 0);
        $this->assertDatabaseCount('class_subjects', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_renaming_regenerates_code_and_editing_preserves_existing_setup(): void
    {
        $c = $this->setupData();
        $class = SchoolClass::factory()->create(['name' => 'Class 2', 'code' => 'C2']);
        Section::factory()->create(['class_id' => $class->id, 'name' => 'A']);
        ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $class->id, 'subject_id' => $c['subjects'][0]->id]);
        $this->actingAs($c['admin'])->put('/academics/classes/'.$class->id, ['name' => 'Class 3', 'code' => 'FORGED'])->assertRedirect();
        $this->assertSame('CLASS-3', $class->fresh()->code);
        $this->assertDatabaseCount('sections', 1);
        $this->assertDatabaseCount('class_subjects', 1);
        $this->put('/academics/classes/'.$class->id, ['name' => 'Class 3', 'sections' => [['name' => 'B']]])->assertSessionHasErrors('setup_revision');
    }

    public function test_copy_choices_only_include_active_subjects_from_current_year_and_school(): void
    {
        $c = $this->setupData();
        $source = SchoolClass::factory()->create(['name' => 'Class 1']);
        ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $source->id, 'subject_id' => $c['subjects'][0]->id]);
        $otherYear = AcademicYear::create(['name' => 'Other year', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31']);
        $excluded = SchoolClass::factory()->create(['name' => 'OTHER-YEAR-CLASS']);
        ClassSubject::factory()->create(['academic_year_id' => $otherYear->id, 'class_id' => $excluded->id, 'subject_id' => $c['subjects'][1]->id]);
        $this->actingAs($c['admin'])->get('/academics/classes/create')->assertOk()->assertViewHas('copyClasses', fn ($classes) => $classes->modelKeys() === [$source->id])->assertSee('Copy subjects from another class');
    }

    public function test_teacher_next_step_keeps_the_new_class_while_selecting_a_teacher(): void
    {
        $c = $this->setupData();
        $class = SchoolClass::factory()->create();
        $this->actingAs($c['admin'])->get('/academics/teacher-assignments/create?'.http_build_query(['academic_year_id' => $c['year']->id, 'class_id' => $class->id, 'assignment_type' => 'CLASS_TEACHER', 'scope' => 'SECTION']))
            ->assertOk()->assertViewHas('step', 'teacher')->assertViewHas('selection', fn ($selection) => $selection['class_id'] === $class->id && $selection['scope'] === 'SECTION');
    }

    private function editableClass(array $c): SchoolClass
    {
        return app(CreateClassWithSetup::class)->create($c['admin'], ['name' => 'Class 2', 'sections' => [['name' => 'A', 'capacity' => 30], ['name' => 'Blue', 'capacity' => 25]], 'academic_year_id' => $c['year']->id, 'subject_ids' => [$c['subjects'][0]->id]]);
    }

    private function editPayload(array $c, SchoolClass $class, array $extra = []): array
    {
        return array_replace(['name' => $class->fresh()->name, 'setup_revision' => ClassSetupState::revision($class), 'sections' => [['name' => 'A', 'capacity' => 30], ['name' => 'Blue', 'capacity' => 25]], 'academic_year_id' => $c['year']->id, 'subject_ids' => [$c['subjects'][0]->id]], $extra);
    }

    public function test_edit_prefills_all_setup_fields_and_can_update_them_without_duplicates(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $a = $class->sections()->where('name', 'A')->firstOrFail();
        $blue = $class->sections()->where('name', 'Blue')->firstOrFail();
        $allocation = $class->classSubjects()->firstOrFail();
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin'])->get($url.'/edit')->assertOk()->assertSee('data-setup-sections', false)->assertSee('data-setup-subjects', false)->assertSee('value="30"', false)
            ->assertViewHas('selectedSubjectIds', [$c['subjects'][0]->id])->assertViewHas('sectionDefaults', fn ($sections) => $sections['A']['capacity'] === 30 && count($sections) === 2);
        $extra = ['name' => 'Class 3', 'sections' => [['name' => 'A', 'capacity' => 35], ['name' => 'B', 'capacity' => 20]], 'subject_ids' => [$c['subjects'][1]->id]];
        $this->put($url, $this->editPayload($c, $class, $extra))->assertRedirect();
        $this->assertSame('CLASS-3', $class->fresh()->code);
        $this->assertSame(35, $a->fresh()->capacity);
        $this->assertDatabaseMissing('sections', ['id' => $blue->id]);
        $this->assertFalse($class->sections()->whereKey($blue->id)->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'SECTION_DELETED', 'entity_id' => $blue->id]);
        $this->assertSame('INACTIVE', $allocation->fresh()->status->value);
        $this->assertSame(2, $class->sections()->where('status', 'ACTIVE')->count());
        $this->assertDatabaseHas('class_subjects', ['class_id' => $class->id, 'subject_id' => $c['subjects'][1]->id, 'status' => 'ACTIVE']);
        $before = DB::table('audit_logs')->count();
        $this->put($url, $this->editPayload($c, $class, $extra))->assertRedirect();
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('sections', 2);
        $this->assertDatabaseCount('class_subjects', 2);
    }

    public function test_edit_recreates_removed_sections_and_reactivates_subjects_and_keeps_marks_settings(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $allocation = $class->classSubjects()->firstOrFail();
        $allocation->fill(['theory_max_marks' => 70, 'practical_max_marks' => 30, 'passing_marks' => 33])->save();
        $url = '/academics/classes/'.$class->id;
        $oldSectionIds = $class->sections()->pluck('id')->all();
        $this->actingAs($c['admin'])->put($url, $this->editPayload($c, $class, ['sections' => [], 'subject_ids' => []]))->assertRedirect();
        $this->assertDatabaseCount('sections', 0);
        $this->assertSame(0, $class->sections()->where('status', 'ACTIVE')->count());
        $this->put($url, $this->editPayload($c, $class))->assertRedirect();
        $this->assertDatabaseCount('sections', 2);
        $this->assertDatabaseCount('class_subjects', 1);
        $this->assertSame('ACTIVE', $allocation->fresh()->status->value);
        $this->assertEmpty(array_intersect($oldSectionIds, $class->sections()->pluck('id')->all()));
        $this->assertSame('70.00', $allocation->fresh()->theory_max_marks);
        $this->assertSame('33.00', $allocation->fresh()->passing_marks);
    }

    public function test_edit_cannot_remove_sections_with_students_or_reduce_capacity_below_enrollment(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $a = $class->sections()->where('name', 'A')->firstOrFail();
        $context = ['admin' => $c['admin'], 'year' => $c['year'], 'class' => $class, 'section' => $a];
        $this->enroll($context, 1);
        $this->enroll($context, 2);
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin'])->get($url.'/edit')->assertOk()->assertViewHas('lockedSectionNames', ['A'])->assertSee('already in use');
        $this->put($url, $this->editPayload($c, $class, ['name' => 'Must not save', 'sections' => []]))->assertSessionHasErrors('sections');
        $this->put($url, $this->editPayload($c, $class, ['sections' => [['name' => 'A', 'capacity' => 1]]]))->assertSessionHasErrors('sections.0.capacity');
        $this->assertSame('Class 2', $class->fresh()->name);
        $this->assertSame(30, $a->fresh()->capacity);
        $this->assertSame(2, $class->sections()->where('status', 'ACTIVE')->count());
        $this->assertDatabaseCount('student_enrollments', 2);
    }

    public function test_edit_keeps_subjects_used_by_an_exam_selected_and_rejects_forged_removal(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $allocation = $class->classSubjects()->firstOrFail();
        $term = app(ExamTermService::class)->save($c['admin'], null, ['academic_year_id' => $c['year']->id, 'name' => 'Annual']);
        $exam = app(ExamService::class)->save($c['admin'], null, ['academic_year_id' => $c['year']->id, 'exam_term_id' => $term->id, 'class_id' => $class->id, 'name' => 'Annual', 'start_date' => '2027-03-01', 'end_date' => '2027-03-10']);
        app(ExamSubjectService::class)->save($c['admin'], $exam, null, ['class_subject_id' => $allocation->id, 'theory_max_marks' => 100, 'practical_max_marks' => null, 'passing_marks' => 33]);
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin'])->get($url.'/edit')->assertOk()->assertViewHas('lockedSubjectIds', [$allocation->subject_id])->assertSee('In use · kept selected');
        $this->put($url, $this->editPayload($c, $class, ['name' => 'Must not save', 'subject_ids' => []]))->assertSessionHasErrors('subject_ids');
        $this->assertSame('Class 2', $class->fresh()->name);
        $this->assertSame('ACTIVE', $allocation->fresh()->status->value);
        $this->assertDatabaseCount('exam_subjects', 1);
    }

    public function test_edit_protects_sections_and_subjects_with_assigned_teachers(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $allocation = $class->classSubjects()->firstOrFail();
        $a = $class->sections()->where('name', 'A')->firstOrFail();
        TeacherAssignment::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $class->id, 'section_id' => $a->id, 'subject_id' => $allocation->subject_id, 'class_subject_id' => $allocation->id, 'assignment_type' => 'SUBJECT_TEACHER']);
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin'])->get($url.'/edit')->assertOk()->assertViewHas('lockedSectionNames', ['A'])->assertViewHas('lockedSubjectIds', [$allocation->subject_id]);
        $this->put($url, $this->editPayload($c, $class, ['sections' => []]))->assertSessionHasErrors('sections');
        $this->put($url, $this->editPayload($c, $class, ['subject_ids' => []]))->assertSessionHasErrors('subject_ids');
        $this->assertDatabaseCount('teacher_assignments', 1);
    }

    public function test_stale_edit_is_rejected_and_reload_displays_the_latest_sections(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $payload = $this->editPayload($c, $class);
        app(SectionService::class)->save($c['admin'], null, ['class_id' => $class->id, 'name' => 'B']);
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin'])->put($url, $payload)->assertSessionHasErrors('setup_revision');
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get($url.'/edit')->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $checkbox = (new \DOMXPath($dom))->query('//input[@data-section-choice="B"]')->item(0);
        $this->assertTrue($checkbox->hasAttribute('checked'));
        $this->assertSame(3, $class->sections()->where('status', 'ACTIVE')->count());
    }

    public function test_edit_requires_setup_permissions_even_when_clearing_all_choices(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $manager = $this->user($c, ['class.view', 'class.manage']);
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($manager)->get($url.'/edit')->assertOk()->assertDontSee('data-setup-sections', false)->assertDontSee('data-setup-subjects', false);
        $this->put($url, $this->editPayload($c, $class, ['sections' => [], 'subject_ids' => []]))->assertForbidden();
        $this->put($url, ['name' => 'Class 3'])->assertRedirect();
        $this->assertDatabaseCount('sections', 2);
        $this->assertDatabaseCount('class_subjects', 1);
    }

    public function test_failed_edit_rolls_back_all_setup_changes_and_audits(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $before = DB::table('audit_logs')->count();
        $logger = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($logger) {
            if ($args[1] === 'CLASS_SUBJECT_CREATED') {
                throw new \RuntimeException('Audit unavailable');
            }

            return $logger->record(...$args);
        });
        try {
            app(UpdateClassWithSetup::class)->update($c['admin'], $class, $this->editPayload($c, $class, ['name' => 'Class 3', 'sections' => [['name' => 'A', 'capacity' => 40], ['name' => 'B', 'capacity' => 20]], 'subject_ids' => [$c['subjects'][1]->id]]));
            $this->fail('A partial edit was saved.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('Class 2', $class->fresh()->name);
        $this->assertSame(2, $class->sections()->where('status', 'ACTIVE')->count());
        $this->assertDatabaseHas('sections', ['class_id' => $class->id, 'name' => 'A', 'capacity' => 30]);
        $this->assertDatabaseCount('class_subjects', 1);
        $this->assertSame('ACTIVE', $class->classSubjects()->first()->status->value);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_edit_retains_inactive_catalog_subjects_and_preserves_other_years(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $oldAllocation = $class->classSubjects()->firstOrFail();
        $c['subjects'][0]->forceFill(['status' => 'INACTIVE'])->save();
        $olderYear = AcademicYear::create(['name' => 'Older', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31']);
        ClassSubject::factory()->create(['academic_year_id' => $olderYear->id, 'class_id' => $class->id, 'subject_id' => $c['subjects'][1]->id]);
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin'])->get($url.'/edit')->assertOk()->assertViewHas('retainedSubjects', fn ($subjects) => $subjects->pluck('id')->all() === [$c['subjects'][0]->id]);
        $this->put($url, $this->editPayload($c, $class, ['subject_ids' => [$c['subjects'][1]->id]]))->assertRedirect();
        $this->assertSame('ACTIVE', $oldAllocation->fresh()->status->value);
        $this->assertDatabaseCount('class_subjects', 3);
        $this->assertDatabaseHas('class_subjects', ['academic_year_id' => $olderYear->id, 'status' => 'ACTIVE']);
    }

    public function test_edit_of_inactive_class_disables_setup_but_allows_name_changes(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $class->forceFill(['status' => 'INACTIVE'])->save();
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin'])->get($url.'/edit')->assertOk()->assertSee('Activate this class before changing sections.')->assertSee('fieldset class="setup-fieldset" disabled', false);
        $this->put($url, $this->editPayload($c, $class))->assertSessionHasErrors('sections');
        $this->put($url, ['name' => 'Class 3'])->assertRedirect();
        $this->assertSame('Class 3', $class->fresh()->name);
    }

    public function test_classes_list_only_shows_active_sections_without_deleting_on_read(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $class->sections()->where('name', 'Blue')->firstOrFail()->forceFill(['status' => 'INACTIVE'])->save();
        $this->actingAs($c['admin'])->get('/academics/classes')->assertOk()->assertDontSee('Blue')
            ->assertViewHas('classes', fn ($classes) => $classes->first()->sections->pluck('name')->all() === ['A']);
        $this->get('/academics/classes/'.$class->id)->assertOk()->assertViewHas('sections', fn ($sections) => $sections->pluck('name')->all() === ['A']);
        $class->sections()->where('name', 'A')->firstOrFail()->forceFill(['status' => 'INACTIVE'])->save();
        $this->get('/academics/classes')->assertOk()->assertSee('No active sections');
        $this->assertDatabaseCount('sections', 2);
    }

    public function test_saving_edit_also_removes_unused_previously_inactive_unchecked_sections(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $blue = $class->sections()->where('name', 'Blue')->firstOrFail();
        $blue->forceFill(['status' => 'INACTIVE'])->save();
        $this->actingAs($c['admin'])->put('/academics/classes/'.$class->id, $this->editPayload($c, $class, ['sections' => [['name' => 'A', 'capacity' => 30]]]))->assertRedirect();
        $this->assertDatabaseMissing('sections', ['id' => $blue->id]);
        $this->assertSame(['A'], $class->sections()->pluck('name')->all());
    }

    public function test_section_removal_protects_promotion_references_without_any_enrollments(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $a = $class->sections()->where('name', 'A')->firstOrFail();
        $blue = $class->sections()->where('name', 'Blue')->firstOrFail();
        $targetYear = AcademicYear::create(['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        PromotionBatch::create(['operation_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'actor_id' => $c['admin']->id, 'mode' => 'PLACEMENT', 'source_academic_year_id' => $c['year']->id, 'source_class_id' => $class->id, 'source_section_id' => $a->id, 'target_academic_year_id' => $targetYear->id, 'target_class_id' => $class->id, 'target_section_id' => $blue->id, 'joined_at' => '2027-04-01']);
        $this->assertTrue($a->isInUse());
        $this->assertTrue($blue->isInUse());
        $this->actingAs($c['admin'])->get('/academics/classes/'.$class->id.'/edit')->assertOk()->assertViewHas('lockedSectionNames', ['A', 'Blue']);
        $this->put('/academics/classes/'.$class->id, $this->editPayload($c, $class, ['sections' => []]))->assertSessionHasErrors('sections');
        $this->assertDatabaseCount('sections', 2);
        $this->assertDatabaseCount('student_enrollments', 0);
        $this->assertDatabaseCount('promotion_batches', 1);
    }

    public function test_used_inactive_sections_are_retained_when_unrelated_setup_is_saved(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $a = $class->sections()->where('name', 'A')->firstOrFail();
        $this->enroll(['admin' => $c['admin'], 'year' => $c['year'], 'class' => $class, 'section' => $a], 1);
        $a->forceFill(['status' => 'INACTIVE'])->save();
        $this->actingAs($c['admin'])->put('/academics/classes/'.$class->id, $this->editPayload($c, $class, ['sections' => [['name' => 'Blue', 'capacity' => 25]]]))->assertRedirect();
        $this->assertDatabaseHas('sections', ['id' => $a->id, 'status' => 'INACTIVE']);
        $this->assertDatabaseCount('student_enrollments', 1);
    }

    public function test_teachers_save_with_new_class_including_custom_sections_and_subjects(): void
    {
        $c = $this->setupData();
        $teachers = TeacherProfile::factory()->count(2)->create();
        $changes = [
            ['section_key' => 'A', 'subject_id' => null, 'teacher_id' => $teachers[0]->id],
            ['section_key' => 'B', 'subject_id' => null, 'teacher_id' => $teachers[1]->id],
            ['section_key' => 'A', 'subject_id' => $c['subjects'][0]->id, 'teacher_id' => $teachers[0]->id],
            ['section_key' => 'B', 'subject_id' => $c['subjects'][0]->id, 'teacher_id' => $teachers[0]->id],
            ['section_key' => 'custom0', 'subject_id' => $c['subjects'][1]->id, 'teacher_id' => $teachers[1]->id],
        ];
        $this->actingAs($c['admin'])->post('/academics/classes', $this->payload($c) + ['teacher_changes' => json_encode($changes)])->assertSessionHasNoErrors()->assertRedirect();
        $class = SchoolClass::firstOrFail();
        $this->assertDatabaseCount('teacher_assignments', 5);
        $this->assertSame(5, DB::table('audit_logs')->where('action', 'TEACHER_ASSIGNMENT_CREATED')->count());
        $this->assertDatabaseHas('teacher_assignments', ['class_id' => $class->id, 'section_id' => $class->sections()->where('name', 'Blue')->value('id'), 'subject_id' => $c['subjects'][1]->id, 'teacher_id' => $teachers[1]->id, 'status' => 'ACTIVE']);
        $this->get('/academics/classes/'.$class->id.'/edit')->assertOk()->assertViewHas('teacherPanelData', fn ($data) => count($data['assignments']) === 5 && count($data['labels']) === 2);
        $this->get('/academics/classes/'.$class->id)->assertSee('/academics/classes/'.$class->id.'/edit#teachers-heading', false);
    }

    public function test_teacher_replacement_retains_history_reactivates_exact_identity_and_noop_has_no_audit_churn(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $teachers = TeacherProfile::factory()->count(2)->create();
        $section = $class->sections()->where('name', 'A')->firstOrFail();
        $original = app(TeacherAssignmentService::class)->create($c['admin'], ['academic_year_id' => $c['year']->id, 'class_id' => $class->id, 'section_id' => $section->id, 'teacher_id' => $teachers[0]->id, 'assignment_type' => 'CLASS_TEACHER']);
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin']);
        foreach ([$teachers[1], $teachers[0]] as $teacher) {
            $data = ['name' => $class->name, 'setup_revision' => ClassSetupState::revision($class), 'academic_year_id' => $c['year']->id, 'teacher_changes' => [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => $teacher->id]]];
            $this->put($url, $data)->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertSame('ACTIVE', $original->fresh()->status->value);
        $this->assertDatabaseCount('teacher_assignments', 2);
        $this->assertDatabaseHas('teacher_assignments', ['teacher_id' => $teachers[1]->id, 'status' => 'INACTIVE']);
        $before = DB::table('audit_logs')->count();
        $data['setup_revision'] = ClassSetupState::revision($class);
        $this->put($url, $data)->assertSessionHasNoErrors();
        $this->put($url, ['name' => $class->name])->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_changing_one_section_preserves_legacy_whole_class_coverage_and_other_co_teachers(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $teachers = TeacherProfile::factory()->count(3)->create();
        $service = app(TeacherAssignmentService::class);
        $identity = ['academic_year_id' => $c['year']->id, 'class_id' => $class->id, 'subject_id' => $c['subjects'][0]->id, 'assignment_type' => 'SUBJECT_TEACHER'];
        $global = $service->create($c['admin'], $identity + ['teacher_id' => $teachers[0]->id]);
        $blue = $class->sections()->where('name', 'Blue')->firstOrFail();
        $coTeacher = $service->create($c['admin'], $identity + ['teacher_id' => $teachers[1]->id, 'section_id' => $blue->id]);
        $this->actingAs($c['admin'])->put('/academics/classes/'.$class->id, ['name' => $class->name, 'setup_revision' => ClassSetupState::revision($class), 'academic_year_id' => $c['year']->id, 'teacher_changes' => [['section_key' => 'A', 'subject_id' => $c['subjects'][0]->id, 'teacher_id' => $teachers[2]->id]]])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('INACTIVE', $global->fresh()->status->value);
        $this->assertSame('ACTIVE', $coTeacher->fresh()->status->value);
        $this->assertDatabaseHas('teacher_assignments', ['teacher_id' => $teachers[0]->id, 'section_id' => $blue->id, 'status' => 'ACTIVE']);
        $this->assertSame(3, TeacherAssignment::where('status', 'ACTIVE')->count());
    }

    public function test_clearing_one_legacy_class_teacher_preserves_the_other_section(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $teacher = TeacherProfile::factory()->create();
        $global = app(TeacherAssignmentService::class)->create($c['admin'], ['academic_year_id' => $c['year']->id, 'class_id' => $class->id, 'teacher_id' => $teacher->id, 'assignment_type' => 'CLASS_TEACHER']);
        $this->actingAs($c['admin'])->put('/academics/classes/'.$class->id, ['name' => $class->name, 'setup_revision' => ClassSetupState::revision($class), 'academic_year_id' => $c['year']->id, 'teacher_changes' => [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => null]]])->assertSessionHasNoErrors();
        $this->assertSame('INACTIVE', $global->fresh()->status->value);
        $this->assertSame([$class->sections()->where('name', 'Blue')->value('id')], TeacherAssignment::where('status', 'ACTIVE')->pluck('section_id')->all());
    }

    public function test_bad_teacher_slots_rollback_the_entire_class_setup(): void
    {
        $c = $this->setupData();
        $teacher = TeacherProfile::factory()->create();
        $base = ['section_key' => 'A', 'subject_id' => null, 'teacher_id' => $teacher->id];
        $before = DB::table('audit_logs')->count();
        foreach ([[$base, $base], [array_replace($base, ['section_key' => 'unselected'])], [array_replace($base, ['subject_id' => $c['subjects'][1]->id])]] as $changes) {
            $payload = $this->payload($c);
            $payload['subject_ids'] = [$c['subjects'][0]->id];
            $this->actingAs($c['admin'])->post('/academics/classes', $payload + ['teacher_changes' => $changes])->assertSessionHasErrors('teacher_changes');
            $this->assertDatabaseCount('classes', 0);
            $this->assertDatabaseCount('sections', 0);
            $this->assertDatabaseCount('class_subjects', 0);
            $this->assertDatabaseCount('teacher_assignments', 0);
            $this->assertSame($before, DB::table('audit_logs')->count());
        }
    }

    public function test_foreign_and_inactive_teachers_and_employees_are_rejected(): void
    {
        $c = $this->setupData();
        $inactive = TeacherProfile::factory()->create(['status' => 'INACTIVE']);
        $disabledEmployee = TeacherProfile::factory()->create();
        $disabledEmployee->employee->forceFill(['status' => 'INACTIVE'])->save();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = TeacherProfile::factory()->create();
        app(CurrentSchool::class)->set($c['admin']->school);
        foreach ([$inactive, $disabledEmployee, $foreign] as $teacher) {
            $this->actingAs($c['admin'])->post('/academics/classes', $this->payload($c) + ['teacher_changes' => [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => $teacher->id]]])->assertSessionHasErrors('teacher_changes.0.teacher_id');
        }
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_teacher_choices_need_permission_current_year_and_valid_payload(): void
    {
        $c = $this->setupData();
        $teacher = TeacherProfile::factory()->create();
        $changes = [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => $teacher->id]];
        $manager = $this->user($c, ['class.view', 'class.manage']);
        $this->actingAs($manager)->get('/academics/classes/create')->assertOk()->assertDontSee('data-class-teachers', false);
        $this->post('/academics/classes', ['name' => 'Class 2', 'teacher_changes' => $changes])->assertForbidden();
        $this->getJson('/academics/classes/teacher-options')->assertForbidden();
        $this->actingAs($c['admin'])->post('/academics/classes', ['name' => 'Class 2', 'teacher_changes' => $changes])->assertSessionHasErrors('academic_year_id');
        foreach (['{bad json', 'null', [['section_key' => 'A', 'teacher_id' => null, 'school_id' => 999]]] as $invalid) {
            $this->post('/academics/classes', $this->payload($c) + ['teacher_changes' => $invalid])->assertSessionHasErrors();
        }
        $this->post('/academics/classes', $this->payload($c) + ['teacher_changes' => array_fill(0, 501, $changes[0])])->assertSessionHasErrors('teacher_changes');
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_teacher_only_manager_can_edit_existing_assignments_without_section_or_subject_permissions(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $teacher = TeacherProfile::factory()->create();
        $manager = $this->user($c, ['class.view', 'class.manage', 'teacher_assignment.view', 'teacher_assignment.manage']);
        $this->actingAs($manager)->get('/academics/classes/'.$class->id.'/edit')->assertOk()->assertDontSee('data-setup-sections', false)->assertDontSee('data-setup-subjects', false)->assertViewHas('teacherPanelData', fn ($data) => count($data['sections']) === 2 && count($data['subjects']) === 1);
        $this->put('/academics/classes/'.$class->id, ['name' => $class->name, 'setup_revision' => ClassSetupState::revision($class), 'academic_year_id' => $c['year']->id, 'teacher_changes' => [['section_key' => 'A', 'subject_id' => $c['subjects'][0]->id, 'teacher_id' => $teacher->id]]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('teacher_assignments', 1);
        $this->assertDatabaseCount('sections', 2);
        $this->assertDatabaseCount('class_subjects', 1);
    }

    public function test_concurrent_teacher_edit_rejects_stale_class_form_and_resets_draft(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $revision = ClassSetupState::revision($class);
        $teacher = TeacherProfile::factory()->create();
        app(TeacherAssignmentService::class)->create($c['admin'], ['academic_year_id' => $c['year']->id, 'class_id' => $class->id, 'section_id' => $class->sections()->first()->id, 'teacher_id' => $teacher->id, 'assignment_type' => 'CLASS_TEACHER']);
        $this->actingAs($c['admin'])->from('/academics/classes/'.$class->id.'/edit')->put('/academics/classes/'.$class->id, ['name' => $class->name, 'setup_revision' => $revision, 'academic_year_id' => $c['year']->id, 'teacher_changes' => [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => null]]])->assertSessionHasErrors('setup_revision');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/academics/classes/'.$class->id.'/edit')->assertOk()->assertViewHas('teacherPanelData', fn ($data) => $data['changes'] === [] && count($data['assignments']) === 1);
        $this->assertSame(1, TeacherAssignment::where('status', 'ACTIVE')->count());
    }

    public function test_late_teacher_failure_rolls_back_everything_including_audit_records(): void
    {
        $c = $this->setupData();
        $teacher = TeacherProfile::factory()->create();
        $before = DB::table('audit_logs')->count();
        $logger = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($logger) {
            if ($args[1] === 'TEACHER_ASSIGNMENT_CREATED') {
                throw new \RuntimeException('Teacher audit unavailable');
            }

            return $logger->record(...$args);
        });
        try {
            app(CreateClassWithSetup::class)->create($c['admin'], ['name' => 'Class 2', 'sections' => ['A' => ['name' => 'A']], 'academic_year_id' => $c['year']->id, 'subject_ids' => $c['subjects']->modelKeys(), 'teacher_changes' => [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => $teacher->id]]]);
            $this->fail('Partial teacher setup was saved.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Teacher audit unavailable', $exception->getMessage());
        }
        foreach (['classes', 'sections', 'class_subjects', 'teacher_assignments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_teacher_picker_is_paginated_searchable_and_excludes_ineligible_and_foreign_records(): void
    {
        $c = $this->setupData();
        $teachers = TeacherProfile::factory()->count(13)->create(['specialization' => 'Science']);
        $teachers[0]->employee->update(['first_name' => 'Ananya', 'middle_name' => null, 'last_name' => 'Rao', 'employee_number' => 'TCH-001']);
        TeacherProfile::factory()->create(['status' => 'INACTIVE', 'specialization' => 'Hidden']);
        $disabled = TeacherProfile::factory()->create();
        $disabled->employee->forceFill(['status' => 'INACTIVE'])->save();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        TeacherProfile::factory()->create(['specialization' => 'Hidden']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $first = $this->actingAs($c['admin'])->getJson('/academics/classes/teacher-options')->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('total', 13)->assertJsonPath('last_page', 2);
        $this->assertSame(['id', 'name', 'employee_number', 'specialization'], array_keys($first->json('data.0')));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->getJson('/academics/classes/teacher-options?page=2')->assertOk()->assertJsonCount(1, 'data');
        foreach (['Ananya Rao', 'TCH-001'] as $q) {
            $this->getJson('/academics/classes/teacher-options?'.http_build_query(['q' => $q]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $teachers[0]->id);
        }
        $this->getJson('/academics/classes/teacher-options?q=Science')->assertOk()->assertJsonPath('total', 13);
        $this->getJson('/academics/classes/teacher-options?q=Hidden')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/academics/classes/teacher-options?page=0')->assertUnprocessable();
        $this->getJson('/academics/classes/teacher-options?q='.str_repeat('x', 101))->assertUnprocessable();
        $viewer = $this->user($c, ['class.view', 'teacher_assignment.view']);
        $this->actingAs($viewer)->getJson('/academics/classes/teacher-options')->assertForbidden();
    }

    public function test_validation_errors_restore_teacher_drafts_without_foreign_teacher_labels(): void
    {
        $c = $this->setupData();
        $teacher = TeacherProfile::factory()->create();
        $changes = [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => $teacher->id]];
        $payload = $this->payload($c);
        $payload['sections']['A']['capacity'] = -1;
        $this->actingAs($c['admin'])->from('/academics/classes/create')->post('/academics/classes', $payload + ['teacher_changes' => json_encode($changes)])->assertSessionHasErrors('sections.A.capacity');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/academics/classes/create')->assertOk()->assertViewHas('teacherPanelData', fn ($data) => $data['changes'] === $changes && $data['labels'][0]['id'] === $teacher->id);
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = TeacherProfile::factory()->create();
        app(CurrentSchool::class)->set($c['admin']->school);
        $changes[0]['teacher_id'] = $foreign->id;
        $this->post('/academics/classes', $this->payload($c) + ['teacher_changes' => $changes])->assertSessionHasErrors('teacher_changes.0.teacher_id');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/academics/classes/create')->assertOk()->assertViewHas('teacherPanelData', fn ($data) => $data['labels'] === []);
    }

    public function test_ineligible_legacy_global_teacher_cannot_be_copied_silently_and_failed_edit_rolls_back(): void
    {
        $c = $this->setupData();
        $class = $this->editableClass($c);
        $teacher = TeacherProfile::factory()->create();
        $global = app(TeacherAssignmentService::class)->create($c['admin'], ['academic_year_id' => $c['year']->id, 'class_id' => $class->id, 'teacher_id' => $teacher->id, 'assignment_type' => 'CLASS_TEACHER']);
        $teacher->forceFill(['status' => 'INACTIVE'])->save();
        $url = '/academics/classes/'.$class->id;
        $this->actingAs($c['admin'])->get($url.'/edit')->assertOk()->assertViewHas('teacherPanelData', fn ($data) => $data['labels'][0]['eligible'] === false);
        $before = DB::table('audit_logs')->count();
        $this->put($url, ['name' => 'Class 9', 'setup_revision' => ClassSetupState::revision($class), 'academic_year_id' => $c['year']->id, 'teacher_changes' => [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => null]]])->assertSessionHasErrors('teacher_changes');
        $this->assertSame('Class 2', $class->fresh()->name);
        $this->assertSame('ACTIVE', $global->fresh()->status->value);
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->put($url, ['name' => $class->name, 'setup_revision' => ClassSetupState::revision($class), 'academic_year_id' => $c['year']->id, 'teacher_changes' => [['section_key' => 'A', 'subject_id' => null, 'teacher_id' => null], ['section_key' => 'existing'.$class->sections()->where('name', 'Blue')->value('id'), 'subject_id' => null, 'teacher_id' => null]]])->assertSessionHasNoErrors();
        $this->assertSame('INACTIVE', $global->fresh()->status->value);
        $this->assertDatabaseCount('teacher_assignments', 1);
    }
}

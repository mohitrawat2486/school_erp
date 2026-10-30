<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ClassSubjectService;
use App\Services\ProvisionSchool;
use App\Services\SubjectService;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $catalog = '/academics/subjects';

    private string $allocations = '/academics/class-subjects';

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('DEMO', 'Demo School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function context(User $owner, string $status = 'DRAFT'): array
    {
        app(CurrentSchool::class)->set($owner->school);
        $year = AcademicYear::create(['name' => fake()->unique()->bothify('Year-########'), 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        $year->status = $status;
        $year->save();

        return ['academic_year_id' => $year->id, 'class_id' => SchoolClass::factory()->create()->id, 'subject_id' => Subject::factory()->create()->id];
    }

    private function marks(array $extra = []): array
    {
        return array_merge(['theory_max_marks' => '70.00', 'practical_max_marks' => '30.00', 'passing_marks' => '33.00'], $extra);
    }

    private function allocation(User $owner, array $ids = []): ClassSubject
    {
        if (! $ids) {
            $ids = $this->context($owner);
        }
        app(CurrentSchool::class)->set($owner->school);

        return ClassSubject::factory()->create([...$ids, ...$this->marks()]);
    }

    public function test_guests_and_unprivileged_accounts_cannot_access_modules(): void
    {
        $this->get($this->catalog)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($this->catalog)->assertForbidden();
        $this->get($this->allocations)->assertForbidden();
        $this->post($this->catalog, ['name' => 'English', 'code' => 'ENG', 'type' => 'CORE'])->assertForbidden();
        $this->post($this->allocations, $this->marks())->assertForbidden();
    }

    public function test_catalog_create_view_edit_and_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get($this->catalog)->assertOk();
        $this->get($this->catalog.'/create')->assertOk();
        $this->post($this->catalog, ['name' => 'English', 'code' => 'ENG', 'type' => 'CORE'])->assertRedirect();
        $id = DB::table('subjects')->value('id');
        $this->get($this->catalog.'/'.$id)->assertOk()->assertSee('English');
        $this->get($this->catalog.'/'.$id.'/edit')->assertOk();
        $this->put($this->catalog.'/'.$id, ['name' => 'English Language', 'code' => 'ENG', 'type' => 'OPTIONAL'])->assertRedirect();
        $this->assertDatabaseHas('subjects', ['id' => $id, 'school_id' => $admin->school_id, 'name' => 'English Language', 'type' => 'OPTIONAL']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SUBJECT_CREATED', 'entity_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SUBJECT_UPDATED', 'entity_id' => $id]);
    }

    public function test_subject_validation_and_school_specific_codes(): void
    {
        $admin = $this->admin();
        $subject = Subject::factory()->create(['code' => 'ENG']);
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        Subject::factory()->create(['code' => 'ENG']);
        $this->actingAs($admin)->post($this->catalog, ['name' => 'English', 'code' => 'ENG', 'type' => 'CORE'])->assertSessionHasErrors('code');
        $this->post($this->catalog, ['name' => '', 'code' => 'bad code', 'type' => 'INVALID', 'school_id' => 999, 'status' => 'INACTIVE'])->assertSessionHasErrors(['name', 'code', 'type', 'school_id', 'status']);
        $this->put($this->catalog.'/'.$subject->id, ['name' => 'English', 'code' => 'ENG', 'type' => 'CORE'])->assertRedirect();
        $this->assertDatabaseCount('subjects', 2);
    }

    public function test_subject_status_retains_records_and_audits(): void
    {
        $admin = $this->admin();
        $subject = Subject::factory()->create();
        $url = $this->catalog.'/'.$subject->id;
        $this->actingAs($admin)->post($url.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('subjects', ['id' => $subject->id, 'status' => 'INACTIVE']);
        $this->post($url.'/activate')->assertRedirect();
        $this->assertDatabaseHas('subjects', ['id' => $subject->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SUBJECT_DEACTIVATED', 'entity_id' => $subject->id]);
        $this->delete($url)->assertStatus(405);
    }

    public function test_allocation_wizard_creates_and_edits_year_class_subject_configuration(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $this->actingAs($admin)->get($this->allocations.'/create')->assertOk()->assertSee('Choose academic year');
        $this->get($this->allocations.'/create?'.http_build_query(['academic_year_id' => $ids['academic_year_id']]))->assertOk()->assertSee('Choose class');
        $this->get($this->allocations.'/create?'.http_build_query(array_diff_key($ids, ['subject_id' => true])))->assertOk()->assertSee('Choose subject');
        $this->get($this->allocations.'/create?'.http_build_query($ids))->assertOk()->assertSee('Assign subject');
        $this->post($this->allocations, [...$ids, ...$this->marks()])->assertRedirect();
        $id = DB::table('class_subjects')->value('id');
        $this->get($this->allocations.'/'.$id)->assertOk()->assertSee('100.00');
        $this->get($this->allocations.'/'.$id.'/edit')->assertOk();
        $this->put($this->allocations.'/'.$id, $this->marks(['passing_marks' => '40.00']))->assertRedirect();
        $this->assertDatabaseHas('class_subjects', ['id' => $id, 'school_id' => $admin->school_id, ...$ids, 'passing_marks' => '40.00']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CLASS_SUBJECT_CREATED', 'entity_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CLASS_SUBJECT_UPDATED', 'entity_id' => $id]);
    }

    public function test_allocation_tuple_is_unique_and_context_is_immutable(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $allocation = $this->allocation($admin, $ids);
        $secondYear = AcademicYear::create(['name' => 'Another year', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $secondClass = SchoolClass::factory()->create();
        $this->actingAs($admin)->post($this->allocations, [...$ids, ...$this->marks()])->assertSessionHasErrors('subject_id');
        $this->post($this->allocations, [...$ids, 'academic_year_id' => $secondYear->id, ...$this->marks()])->assertRedirect();
        $this->post($this->allocations, [...$ids, 'class_id' => $secondClass->id, ...$this->marks()])->assertRedirect();
        $this->put($this->allocations.'/'.$allocation->id, [...$ids, ...$this->marks()])->assertSessionHasErrors(['academic_year_id', 'class_id', 'subject_id']);
        $this->assertDatabaseCount('class_subjects', 3);
    }

    public function test_marks_limits_use_exact_decimals_and_reject_invalid_ranges(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $this->actingAs($admin);
        foreach ([['theory_max_marks' => '-1'], ['practical_max_marks' => '1.001'], ['passing_marks' => '100.01'], ['theory_max_marks' => '0', 'practical_max_marks' => '0', 'passing_marks' => null], ['theory_max_marks' => null, 'practical_max_marks' => null]] as $bad) {
            $this->post($this->allocations, [...$ids, ...$this->marks($bad)])->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('class_subjects', 0);
        $this->post($this->allocations, [...$ids, ...$this->marks(['theory_max_marks' => '0.10', 'practical_max_marks' => '0.20', 'passing_marks' => '0.30'])])->assertRedirect();
        $id = DB::table('class_subjects')->value('id');
        $this->get($this->allocations.'/'.$id)->assertOk()->assertSee('0.30');
        $this->put($this->allocations.'/'.$id, ['practical_max_marks' => '0.19'])->assertSessionHasErrors('passing_marks');
        $this->assertDatabaseHas('class_subjects', ['id' => $id, 'practical_max_marks' => '0.20']);
    }

    public function test_unconfigured_marks_are_nullable_and_theory_only_is_supported(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $this->actingAs($admin)->post($this->allocations, $ids)->assertRedirect();
        $id = DB::table('class_subjects')->value('id');
        $this->assertDatabaseHas('class_subjects', ['id' => $id, 'theory_max_marks' => null, 'practical_max_marks' => null, 'passing_marks' => null]);
        $this->get($this->allocations.'/'.$id)->assertOk()->assertSee('Not configured');
        $this->put($this->allocations.'/'.$id, ['theory_max_marks' => '100', 'passing_marks' => '100'])->assertRedirect();
        $this->assertDatabaseHas('class_subjects', ['id' => $id, 'theory_max_marks' => '100.00', 'passing_marks' => '100.00']);
    }

    public function test_closed_years_are_read_only_and_configured_drafts_cannot_be_removed(): void
    {
        $admin = $this->admin();
        $allocation = $this->allocation($admin);
        $yearId = $allocation->academic_year_id;
        $url = $this->allocations.'/'.$allocation->id;
        $this->actingAs($admin)->delete('/academics/academic-years/'.$yearId)->assertSessionHasErrors('academic_year');
        $this->post('/academics/academic-years/'.$yearId.'/activate')->assertRedirect();
        $this->post('/academics/academic-years/'.$yearId.'/close')->assertRedirect();
        $this->get($url)->assertOk()->assertDontSee('Edit allocation');
        $this->get($url.'/edit')->assertForbidden();
        $this->put($url, $this->marks(['passing_marks' => '50']))->assertSessionHasErrors('academic_year_id');
        $this->post($url.'/deactivate')->assertSessionHasErrors('academic_year_id');
        $this->assertDatabaseHas('class_subjects', ['id' => $allocation->id, 'passing_marks' => '33.00', 'status' => 'ACTIVE']);
        $this->post('/academics/academic-years/'.$yearId.'/archive')->assertRedirect();
        $this->put($url, $this->marks())->assertSessionHasErrors('academic_year_id');
    }

    public function test_inactive_parents_and_closed_years_are_not_selectable_for_creation(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $this->actingAs($admin);
        DB::table('academic_years')->where('id', $ids['academic_year_id'])->update(['status' => 'CLOSED']);
        $this->post($this->allocations, [...$ids, ...$this->marks()])->assertSessionHasErrors('academic_year_id');
        $this->get($this->allocations.'/create?'.http_build_query($ids))->assertNotFound();
        DB::table('academic_years')->where('id', $ids['academic_year_id'])->update(['status' => 'DRAFT']);
        DB::table('classes')->where('id', $ids['class_id'])->update(['status' => 'INACTIVE']);
        $this->post($this->allocations, [...$ids, ...$this->marks()])->assertSessionHasErrors('class_id');
        DB::table('classes')->where('id', $ids['class_id'])->update(['status' => 'ACTIVE']);
        DB::table('subjects')->where('id', $ids['subject_id'])->update(['status' => 'INACTIVE']);
        $this->post($this->allocations, [...$ids, ...$this->marks()])->assertSessionHasErrors('subject_id');
    }

    public function test_subject_deactivation_preserves_historical_allocations_and_changes_eligibility(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin, 'ACTIVE');
        $allocation = $this->allocation($admin, $ids);
        $this->assertTrue($allocation->isEligible());
        $this->actingAs($admin)->post($this->catalog.'/'.$ids['subject_id'].'/deactivate')->assertRedirect();
        app(CurrentSchool::class)->set($admin->school);
        $this->assertFalse($allocation->fresh()->isEligible());
        $this->assertSame(0, ClassSubject::eligible()->count());
        $this->assertDatabaseHas('class_subjects', ['id' => $allocation->id, 'status' => 'ACTIVE', 'theory_max_marks' => '70.00']);
        $this->post($this->allocations.'/'.$allocation->id.'/deactivate')->assertRedirect();
        $this->post($this->allocations.'/'.$allocation->id.'/activate')->assertSessionHasErrors('context');
        $this->post($this->catalog.'/'.$ids['subject_id'].'/activate')->assertRedirect();
        $this->post($this->allocations.'/'.$allocation->id.'/activate')->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'CLASS_SUBJECT_ACTIVATED', 'entity_id' => $allocation->id]);
        $this->delete($this->allocations.'/'.$allocation->id)->assertStatus(405);
    }

    public function test_view_only_permissions_prevent_changes_in_both_modules(): void
    {
        $admin = $this->admin();
        $allocation = $this->allocation($admin);
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'subject.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $this->actingAs($reader)->get($this->catalog)->assertOk()->assertDontSee('Add subject');
        $this->get($this->allocations)->assertOk()->assertDontSee(route('class-subjects.create'));
        $this->get($this->allocations.'/filter')->assertOk();
        foreach ([$this->catalog.'/'.$allocation->subject_id, $this->allocations.'/'.$allocation->id] as $url) {
            $this->get($url)->assertOk();
            $this->get($url.'/edit')->assertForbidden();
            $this->put($url, $this->marks())->assertForbidden();
            $this->post($url.'/activate')->assertForbidden();
            $this->post($url.'/deactivate')->assertForbidden();
        }
        $this->get($this->catalog.'/create')->assertForbidden();
        $this->get($this->allocations.'/create')->assertForbidden();
    }

    public function test_subject_manager_does_not_need_year_or_class_management_permissions(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $manager = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, DB::table('permissions')->whereIn('name', ['subject.view', 'subject.manage'])->pluck('id')->all());
        app(AccessManager::class)->assignRoles($admin, $manager, [$role->id]);
        $this->actingAs($manager)->get($this->allocations.'/create?'.http_build_query($ids))->assertOk();
        $this->post($this->allocations, [...$ids, ...$this->marks()])->assertRedirect();
        $this->get('/academics/classes')->assertForbidden();
        $this->get('/academics/academic-years')->assertForbidden();
    }

    public function test_foreign_subject_allocation_urls_and_filters_are_rejected(): void
    {
        $admin = $this->admin();
        $own = $this->context($admin);
        $other = User::factory()->create();
        $foreign = $this->allocation($other);
        $this->actingAs($admin);
        foreach ([$this->catalog.'/'.$foreign->subject_id, $this->allocations.'/'.$foreign->id] as $url) {
            $this->get($url)->assertNotFound();
            $this->get($url.'/edit')->assertNotFound();
            $this->put($url, $this->marks())->assertNotFound();
            $this->post($url.'/activate')->assertNotFound();
            $this->post($url.'/deactivate')->assertNotFound();
        }
        foreach (['academic_year_id', 'class_id', 'subject_id'] as $field) {
            $bad = [...$own, $field => $foreign->$field];
            $this->post($this->allocations, [...$bad, ...$this->marks()])->assertSessionHasErrors($field);
            $this->get($this->allocations.'/create?'.http_build_query($bad))->assertNotFound();
            $this->get($this->allocations.'?'.$field.'='.$foreign->$field)->assertSessionHasErrors($field);
        }
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(ClassSubjectService::class)->save($admin, $foreign, $this->marks());
    }

    public function test_subject_and_allocation_lists_and_pickers_are_paginated(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        Subject::factory()->count(23)->sequence(fn ($s) => ['name' => 'Matching '.$s->index])->create();
        ClassSubject::factory()->count(23)->create(['academic_year_id' => $ids['academic_year_id'], 'class_id' => $ids['class_id']]);
        $this->actingAs($admin)->get($this->catalog.'?q=Matching&type=CORE')->assertOk()->assertViewHas('subjects', fn ($rows) => $rows->count() === 20 && $rows->total() === 23);
        $this->get($this->allocations.'?academic_year_id='.$ids['academic_year_id'].'&class_id='.$ids['class_id'])->assertOk()->assertViewHas('allocations', fn ($rows) => $rows->count() === 20 && $rows->total() === 23 && str_contains($rows->url(2), 'class_id='));
        $this->get($this->allocations.'/create?academic_year_id='.$ids['academic_year_id'].'&class_id='.$ids['class_id'].'&q=Matching')->assertOk()->assertViewHas('items', fn ($rows) => $rows->count() === 20 && $rows->total() === 23);
        $this->get($this->allocations.'/filter?academic_year_id='.$ids['academic_year_id'].'&class_id='.$ids['class_id'])->assertRedirect($this->allocations.'?academic_year_id='.$ids['academic_year_id'].'&class_id='.$ids['class_id']);
    }

    public function test_database_rejects_duplicate_allocations_and_foreign_contexts(): void
    {
        $admin = $this->admin();
        $own = $this->context($admin);
        $existing = $this->allocation($admin, $own);
        $other = User::factory()->create();
        $foreign = $this->context($other);
        foreach (['duplicate', 'academic_year_id', 'class_id', 'subject_id'] as $field) {
            $bad = $field === 'duplicate' ? $own : [...$own, $field => $foreign[$field]];
            try {
                DB::table('class_subjects')->insert(['school_id' => $admin->school_id, ...$bad]);
                $this->fail('Invalid context accepted: '.$field);
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('class_subjects', 1);
    }

    public function test_database_rejects_negative_or_impossible_marks_configurations(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        foreach ([['theory_max_marks' => -1], ['theory_max_marks' => 100, 'passing_marks' => 101], ['theory_max_marks' => 0, 'practical_max_marks' => 0], ['passing_marks' => 33]] as $bad) {
            try {
                DB::table('class_subjects')->insert(['school_id' => $admin->school_id, ...$ids, ...$bad]);
                $this->fail('Invalid marks accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('class_subjects', 0);
    }

    public function test_audit_failure_rolls_back_subject_and_allocation_creation(): void
    {
        $admin = $this->admin();
        $ids = $this->context($admin);
        $this->mock(AuditLogger::class)->shouldReceive('record')->twice()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(SubjectService::class)->save($admin, null, ['name' => 'Another', 'code' => 'NEW', 'type' => 'CORE']);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }
        try {
            app(ClassSubjectService::class)->save($admin, null, [...$ids, ...$this->marks()]);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failed', $e->getMessage());
        }
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('class_subjects', 0);
    }
}

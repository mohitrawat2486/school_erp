<?php

namespace Tests\Feature;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;
use App\Models\Role;
use App\Models\User;
use App\Services\AcademicYearService;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/academics/academic-years';

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('DEMO', 'Demo School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function data(array $extra = []): array
    {
        return array_merge(['name' => '2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31'], $extra);
    }

    private function year(User $admin, array $extra = []): AcademicYear
    {
        app(CurrentSchool::class)->set($admin->school);

        return app(AcademicYearService::class)->create($admin, $this->data($extra));
    }

    private function row(AcademicYear $year): object
    {
        return DB::table('academic_years')->where('id', $year->id)->first();
    }

    public function test_guests_and_unprivileged_users_are_denied(): void
    {
        $this->get($this->base)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($this->base)->assertForbidden();
        $this->post($this->base, $this->data())->assertForbidden();
    }

    public function test_admin_can_create_view_and_edit_a_draft_with_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get($this->base)->assertOk()->assertSee('No academic years yet');
        $this->get($this->base.'/create')->assertOk();
        $this->post($this->base, $this->data())->assertRedirect();
        $id = DB::table('academic_years')->value('id');
        $this->get($this->base.'/'.$id)->assertOk()->assertSee('2026-27')->assertSee('DRAFT');
        $this->get($this->base.'/'.$id.'/edit')->assertOk();
        $this->put($this->base.'/'.$id, $this->data(['name' => 'Session 2026']))->assertRedirect();
        $this->assertDatabaseHas('academic_years', ['id' => $id, 'school_id' => $admin->school_id, 'name' => 'Session 2026', 'is_current' => false]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_YEAR_CREATED', 'entity_id' => $id, 'module' => 'academics']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_YEAR_UPDATED', 'entity_id' => $id]);
    }

    public function test_invalid_dates_and_protected_fields_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post($this->base, $this->data(['name' => '', 'start_date' => 'not-date', 'end_date' => 'not-date', 'school_id' => 9, 'status' => 'ACTIVE', 'is_current' => true]))->assertSessionHasErrors(['name', 'start_date', 'end_date', 'school_id', 'status', 'is_current']);
        $this->post($this->base, $this->data(['end_date' => '2026-04-01']))->assertSessionHasErrors('end_date');
        $this->post($this->base, $this->data(['end_date' => '2026-03-31']))->assertSessionHasErrors('end_date');
        $this->assertDatabaseCount('academic_years', 0);
    }

    public function test_name_uniqueness_is_per_school_and_includes_removed_drafts(): void
    {
        $admin = $this->admin();
        $year = $this->year($admin);
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        AcademicYear::create($this->data());
        $this->actingAs($admin)->post($this->base, $this->data())->assertSessionHasErrors('name');
        $this->delete($this->base.'/'.$year->id)->assertRedirect($this->base);
        $this->post($this->base, $this->data())->assertSessionHasErrors('name');
        $this->assertSoftDeleted('academic_years', ['id' => $year->id]);
        $this->get($this->base.'/'.$year->id)->assertNotFound();
    }

    public function test_activation_switches_current_and_retains_history(): void
    {
        $admin = $this->admin();
        $one = $this->year($admin);
        $two = $this->year($admin, ['name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
        $this->actingAs($admin)->post($this->base.'/'.$one->id.'/activate')->assertRedirect();
        $this->post($this->base.'/'.$two->id.'/activate')->assertRedirect();
        $this->assertSame('ACTIVE', $this->row($one)->status);
        $this->assertSame(0, $this->row($one)->is_current);
        $this->assertSame(1, $this->row($two)->is_current);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_YEAR_CURRENT_REMOVED', 'entity_id' => $one->id]);
        $this->get('/dashboard')->assertOk()->assertSee('2027-28');
        $this->post($this->base.'/'.$one->id.'/activate')->assertRedirect();
        $this->assertSame(1, $this->row($one)->is_current);
        $this->assertSame(0, $this->row($two)->is_current);
    }

    public function test_close_and_archive_clear_current_and_are_terminal(): void
    {
        $admin = $this->admin();
        $year = $this->year($admin);
        $url = $this->base.'/'.$year->id;
        $this->actingAs($admin)->post($url.'/activate')->assertRedirect();
        $this->post($url.'/close')->assertRedirect();
        $this->assertSame('CLOSED', $this->row($year)->status);
        $this->assertSame(0, $this->row($year)->is_current);
        $this->post($url.'/activate')->assertSessionHasErrors('academic_year');
        $this->post($url.'/archive')->assertRedirect();
        $this->assertSame('ARCHIVED', $this->row($year)->status);
        $this->post($url.'/activate')->assertSessionHasErrors('academic_year');
        $this->get($url)->assertOk()->assertSee('ARCHIVED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_YEAR_CLOSED', 'entity_id' => $year->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_YEAR_ARCHIVED', 'entity_id' => $year->id]);
    }

    public function test_invalid_transitions_and_active_edits_or_deletion_are_rejected(): void
    {
        $admin = $this->admin();
        $year = $this->year($admin);
        $url = $this->base.'/'.$year->id;
        $this->actingAs($admin)->post($url.'/close')->assertSessionHasErrors('academic_year');
        $this->post($url.'/archive')->assertSessionHasErrors('academic_year');
        $this->post($url.'/activate')->assertRedirect();
        $this->get($url.'/edit')->assertForbidden();
        $this->put($url, $this->data(['name' => 'Changed']))->assertSessionHasErrors('academic_year');
        $this->delete($url)->assertSessionHasErrors('academic_year');
        $this->assertSame('2026-27', $this->row($year)->name);
        $this->assertNull($this->row($year)->deleted_at);
    }

    public function test_view_only_role_cannot_mutate(): void
    {
        $admin = $this->admin();
        $year = $this->year($admin);
        $member = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'academic_year.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $member, [$role->id]);
        $url = $this->base.'/'.$year->id;
        $this->actingAs($member)->get($this->base)->assertOk()->assertDontSee('Add academic year');
        $this->get($url)->assertOk()->assertDontSee('Activate &amp; make current');
        $this->get($this->base.'/create')->assertForbidden();
        $this->post($this->base, $this->data())->assertForbidden();
        $this->put($url, $this->data())->assertForbidden();
        foreach (['activate', 'close', 'archive'] as $action) {
            $this->post($url.'/'.$action)->assertForbidden();
        }
        $this->delete($url)->assertForbidden();
    }

    public function test_foreign_school_records_return_404_for_every_route(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $year = AcademicYear::create($this->data(['name' => 'Foreign school year']));
        $url = $this->base.'/'.$year->id;
        $this->actingAs($admin)->get($this->base)->assertOk()->assertDontSee('Foreign school year');
        $this->get($url)->assertNotFound();
        $this->get($url.'/edit')->assertNotFound();
        $this->put($url, $this->data())->assertNotFound();
        $this->delete($url)->assertNotFound();
        foreach (['activate', 'close', 'archive'] as $action) {
            $this->post($url.'/'.$action)->assertNotFound();
        }
    }

    public function test_database_rejects_two_current_years_even_when_model_events_are_bypassed(): void
    {
        $admin = $this->admin();
        $one = $this->year($admin);
        $two = $this->year($admin, ['name' => 'Second']);
        DB::table('academic_years')->where('id', $one->id)->update(['status' => 'ACTIVE', 'is_current' => true]);
        $this->expectException(QueryException::class);
        DB::table('academic_years')->where('id', $two->id)->update(['status' => 'ACTIVE', 'is_current' => true]);
    }

    public function test_separate_schools_can_each_have_a_current_year(): void
    {
        $admin = $this->admin();
        $year = $this->year($admin);
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = AcademicYear::create($this->data());
        foreach ([$year, $foreign] as $record) {
            DB::table('academic_years')->where('id', $record->id)->update(['status' => 'ACTIVE', 'is_current' => true]);
        }
        $this->assertSame(2, DB::table('academic_years')->where('is_current', true)->count());
    }

    public function test_database_rejects_current_draft_year(): void
    {
        $admin = $this->admin();
        $year = $this->year($admin);
        $this->expectException(QueryException::class);
        DB::table('academic_years')->where('id', $year->id)->update(['is_current' => true]);
    }

    public function test_audit_failure_rolls_back_current_year_switch(): void
    {
        $admin = $this->admin();
        $one = $this->year($admin);
        $two = $this->year($admin, ['name' => 'Second']);
        $service = app(AcademicYearService::class);
        $service->transition($admin, $one, 'activate');
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            $service->transition($admin, $two, 'activate');
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertSame(1, $this->row($one)->is_current);
        $this->assertSame(0, $this->row($two)->is_current);
        $this->assertSame(AcademicYearStatus::Draft->value, $this->row($two)->status);
    }

    public function test_database_rejects_non_boolean_current_flags(): void
    {
        $admin = $this->admin();
        $year = $this->year($admin);
        $this->expectException(QueryException::class);
        DB::table('academic_years')->where('id', $year->id)->update(['status' => 'ACTIVE', 'is_current' => 2]);
    }
}

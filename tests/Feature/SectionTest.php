<?php

namespace Tests\Feature;

use App\Enums\ClassStatus;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Services\SchoolClassService;
use App\Services\SectionService;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SectionTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/academics/sections';

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('DEMO', 'Demo School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function schoolClass(User $owner, array $extra = []): SchoolClass
    {
        app(CurrentSchool::class)->set($owner->school);

        return SchoolClass::factory()->create($extra);
    }

    private function section(User $owner, array $extra = []): Section
    {
        app(CurrentSchool::class)->set($owner->school);

        return Section::factory()->create($extra);
    }

    private function data(array $extra = []): array
    {
        return array_merge(['name' => 'A', 'capacity' => 40], $extra);
    }

    public function test_guests_and_accounts_without_section_permissions_are_denied(): void
    {
        $this->get($this->base)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($this->base)->assertForbidden();
        $this->post($this->base, $this->data(['class_id' => 1]))->assertForbidden();
    }

    public function test_create_read_and_update_reuse_class_and_record_audits(): void
    {
        $admin = $this->admin();
        $class = $this->schoolClass($admin, ['name' => '5', 'code' => 'C5']);
        $this->actingAs($admin)->get($this->base)->assertOk();
        $this->get($this->base.'/create?class_id='.$class->id)->assertOk()->assertSee('C5');
        $this->post($this->base, $this->data(['class_id' => $class->id]))->assertRedirect();
        $id = DB::table('sections')->value('id');
        $this->get($this->base.'/'.$id)->assertOk()->assertSee('C5');
        $this->get($this->base.'/'.$id.'/edit')->assertOk();
        $this->put($this->base.'/'.$id, $this->data(['name' => 'B', 'capacity' => 45]))->assertRedirect();
        $this->assertDatabaseHas('sections', ['id' => $id, 'school_id' => $admin->school_id, 'class_id' => $class->id, 'name' => 'B', 'capacity' => 45, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SECTION_CREATED', 'entity_id' => $id, 'module' => 'academics']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SECTION_UPDATED', 'entity_id' => $id]);
        $this->get('/academics/classes/'.$class->id)->assertOk()->assertSee('View sections');
        $this->assertDatabaseCount('classes', 1);
    }

    public function test_names_are_unique_per_class_including_inactive_sections(): void
    {
        $admin = $this->admin();
        $one = $this->section($admin, ['status' => 'INACTIVE']);
        $two = $this->section($admin);
        $other = User::factory()->create();
        $this->section($other);
        $this->actingAs($admin)->post($this->base, $this->data(['class_id' => $one->class_id]))->assertSessionHasErrors('name');
        $this->put($this->base.'/'.$two->id, $this->data())->assertRedirect();
        $this->assertDatabaseCount('sections', 3);
    }

    public function test_capacity_input_and_protected_fields_are_validated(): void
    {
        $admin = $this->admin();
        $class = $this->schoolClass($admin);
        $this->actingAs($admin)->post($this->base, $this->data(['class_id' => $class->id, 'name' => '', 'capacity' => -1, 'school_id' => 999, 'status' => 'INACTIVE', 'academic_year_id' => 1]))->assertSessionHasErrors(['name', 'capacity', 'school_id', 'status', 'academic_year_id']);
        foreach (['0', '1.5', '65536', 'not-numeric'] as $capacity) {
            $this->post($this->base, $this->data(['class_id' => $class->id, 'capacity' => $capacity]))->assertSessionHasErrors('capacity');
        }
        $this->post($this->base, $this->data(['class_id' => $class->id, 'capacity' => null]))->assertRedirect();
        $this->assertDatabaseHas('sections', ['class_id' => $class->id, 'capacity' => null]);
    }

    public function test_existing_section_cannot_move_between_classes(): void
    {
        $admin = $this->admin();
        $section = $this->section($admin);
        $other = $this->schoolClass($admin);
        $this->actingAs($admin)->put($this->base.'/'.$section->id, $this->data(['class_id' => $other->id]))->assertSessionHasErrors('class_id');
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'class_id' => $section->class_id]);
    }

    public function test_class_picker_is_scoped_and_creation_requires_active_class(): void
    {
        $admin = $this->admin();
        $active = $this->schoolClass($admin);
        $inactive = $this->schoolClass($admin, ['status' => 'INACTIVE']);
        $other = User::factory()->create();
        $foreign = $this->schoolClass($other);
        $this->actingAs($admin)->get($this->base.'/create')->assertOk()->assertSee($active->code)->assertDontSee($inactive->code)->assertDontSee($foreign->code);
        $this->get($this->base.'/classes')->assertOk()->assertSee($inactive->code)->assertDontSee($foreign->code);
        foreach ([$inactive, $foreign] as $class) {
            $this->get($this->base.'/create?class_id='.$class->id)->assertNotFound();
            $this->post($this->base, $this->data(['class_id' => $class->id]))->assertSessionHasErrors('class_id');
        }
    }

    public function test_view_only_access_cannot_mutate_sections(): void
    {
        $admin = $this->admin();
        $section = $this->section($admin);
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'section.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $url = $this->base.'/'.$section->id;
        $this->actingAs($reader)->get($this->base)->assertOk()->assertDontSee('Add section');
        $this->get($url)->assertOk()->assertDontSee('Edit section')->assertDontSee('Confirm deactivation')->assertDontSee('View class record');
        $this->get($this->base.'/classes')->assertOk();
        $this->get($this->base.'/create')->assertForbidden();
        $this->get($url.'/edit')->assertForbidden();
        $this->post($this->base, $this->data(['class_id' => $section->class_id]))->assertForbidden();
        $this->put($url, $this->data())->assertForbidden();
        $this->post($url.'/activate')->assertForbidden();
        $this->post($url.'/deactivate')->assertForbidden();
    }

    public function test_section_management_is_independent_of_class_management(): void
    {
        $admin = $this->admin();
        $class = $this->schoolClass($admin);
        $manager = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        $ids = DB::table('permissions')->whereIn('name', ['section.view', 'section.manage'])->pluck('id')->all();
        app(AccessManager::class)->setPermissions($admin, $role, $ids);
        app(AccessManager::class)->assignRoles($admin, $manager, [$role->id]);
        $this->actingAs($manager)->get($this->base.'/create')->assertOk()->assertSee($class->code);
        $this->post($this->base, $this->data(['class_id' => $class->id]))->assertRedirect();
        $this->get('/academics/classes')->assertForbidden();
    }

    public function test_foreign_section_routes_and_class_filters_are_rejected(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $foreign = $this->section($other, ['name' => 'FOREIGN_SECRET']);
        $url = $this->base.'/'.$foreign->id;
        $this->actingAs($admin)->get($this->base)->assertOk()->assertDontSee('FOREIGN_SECRET');
        $this->get($url)->assertNotFound();
        $this->get($url.'/edit')->assertNotFound();
        $this->put($url, $this->data())->assertNotFound();
        $this->post($url.'/activate')->assertNotFound();
        $this->post($url.'/deactivate')->assertNotFound();
        $this->get($this->base.'?class_id='.$foreign->class_id)->assertSessionHasErrors('class_id');
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(SectionService::class)->save($admin, $foreign, $this->data());
    }

    public function test_search_filters_and_pagination_retain_parameters(): void
    {
        $admin = $this->admin();
        $class = $this->schoolClass($admin, ['name' => 'Nursery', 'code' => 'NUR']);
        $one = $this->section($admin, ['class_id' => $class->id, 'name' => 'A0']);
        $two = $this->section($admin, ['name' => 'B', 'status' => 'INACTIVE']);
        $this->actingAs($admin)->get($this->base.'?q=0&status=ACTIVE')->assertOk()->assertSee('A0')->assertDontSee(route('sections.show', $two));
        $this->get($this->base.'?class_id='.$class->id.'&q=Nursery')->assertOk()->assertSee('A0')->assertDontSee(route('sections.show', $two));
        $this->get($this->base.'?status=INACTIVE')->assertOk()->assertSee(route('sections.show', $two))->assertDontSee(route('sections.show', $one));
        app(CurrentSchool::class)->set($admin->school);
        Section::factory()->count(23)->sequence(fn ($sequence) => ['name' => 'Match'.$sequence->index])->create(['class_id' => $class->id]);
        $this->get($this->base.'?q=Match&class_id='.$class->id)->assertOk()->assertViewHas('sections', fn ($rows) => $rows->count() === 20 && $rows->total() === 23 && str_contains($rows->url(2), 'class_id='.$class->id));
        app(CurrentSchool::class)->set($admin->school);
        SchoolClass::factory()->count(23)->sequence(fn ($sequence) => ['name' => 'Picker'.$sequence->index])->create();
        $this->get($this->base.'/create?q=Picker')->assertOk()->assertViewHas('classes', fn ($rows) => $rows->count() === 20 && $rows->total() === 23);
    }

    public function test_class_deactivation_cascades_and_reactivation_remains_explicit(): void
    {
        $admin = $this->admin();
        $section = $this->section($admin);
        $classUrl = '/academics/classes/'.$section->class_id;
        $url = $this->base.'/'.$section->id;
        $this->actingAs($admin)->post($classUrl.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'status' => 'INACTIVE', 'capacity' => 40]);
        $this->post($url.'/activate')->assertSessionHasErrors('class_id');
        $this->post($classUrl.'/activate')->assertRedirect();
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'status' => 'INACTIVE']);
        $this->post($url.'/activate')->assertRedirect();
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'status' => 'ACTIVE']);
        $this->post($url.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('classes', ['id' => $section->class_id, 'status' => 'ACTIVE']);
        $this->delete($url)->assertStatus(405);
    }

    public function test_class_deactivation_processes_all_section_batches(): void
    {
        $admin = $this->admin();
        $class = $this->schoolClass($admin);
        Section::factory()->count(101)->sequence(fn ($sequence) => ['name' => 'S'.$sequence->index])->create(['class_id' => $class->id]);
        app(SchoolClassService::class)->setStatus($admin, $class, ClassStatus::Inactive);
        $this->assertSame(101, DB::table('sections')->where('class_id', $class->id)->where('status', 'INACTIVE')->count());
        $this->assertSame(101, DB::table('audit_logs')->where('action', 'SECTION_DEACTIVATED')->count());
    }

    public function test_database_rejects_duplicate_section_names_for_a_class(): void
    {
        $admin = $this->admin();
        $section = $this->section($admin);
        $this->expectException(QueryException::class);
        DB::table('sections')->insert(['school_id' => $admin->school_id, 'class_id' => $section->class_id, 'name' => 'A']);
    }

    public function test_database_rejects_foreign_class_relationships(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $class = $this->schoolClass($other);
        $this->expectException(QueryException::class);
        DB::table('sections')->insert(['school_id' => $admin->school_id, 'class_id' => $class->id, 'name' => 'A']);
    }

    public function test_database_rejects_zero_capacity(): void
    {
        $admin = $this->admin();
        $class = $this->schoolClass($admin);
        $this->expectException(QueryException::class);
        DB::table('sections')->insert(['school_id' => $admin->school_id, 'class_id' => $class->id, 'name' => 'A', 'capacity' => 0]);
    }

    public function test_eligibility_requires_active_section_and_parent_class(): void
    {
        $admin = $this->admin();
        $active = $this->section($admin);
        $inactive = $this->section($admin, ['status' => 'INACTIVE']);
        $inactiveClass = $this->schoolClass($admin, ['status' => 'INACTIVE']);
        $orphanActive = $this->section($admin, ['class_id' => $inactiveClass->id]);
        $this->assertTrue($active->isEligible());
        $this->assertFalse($inactive->isEligible());
        $this->assertFalse($orphanActive->isEligible());
        $this->assertSame([$active->id], Section::eligible()->pluck('id')->all());
    }

    public function test_audit_failure_rolls_back_class_and_all_section_deactivations(): void
    {
        $admin = $this->admin();
        $section = $this->section($admin);
        $class = $section->schoolClass;
        $count = DB::table('audit_logs')->count();
        $real = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('record')->twice()->andReturnUsing(function (...$args) use ($real) {
            if ($args[1] === 'CLASS_DEACTIVATED') {
                throw new \RuntimeException('Audit failed');
            }

            return $real->record(...$args);
        });
        try {
            app(SchoolClassService::class)->setStatus($admin, $class, ClassStatus::Inactive);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'status' => 'ACTIVE']);
        $this->assertSame($count, DB::table('audit_logs')->count());
    }

    public function test_failed_section_audit_rolls_back_creation(): void
    {
        $admin = $this->admin();
        $class = $this->schoolClass($admin);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(SectionService::class)->save($admin, null, $this->data(['class_id' => $class->id]));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('sections', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ClassStatus;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Services\SchoolClassService;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolClassTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/academics/classes';

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

    private function data(array $extra = []): array
    {
        return array_merge(['name' => 'Nursery', 'code' => 'NUR', 'sort_order' => 0], $extra);
    }

    public function test_guests_and_accounts_without_class_permissions_are_denied(): void
    {
        $this->get($this->base)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($this->base)->assertForbidden();
        $this->post($this->base, $this->data())->assertForbidden();
    }

    public function test_manager_can_create_read_and_update_classes_with_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get($this->base)->assertOk();
        $this->get($this->base.'/create')->assertOk();
        $this->post($this->base, $this->data())->assertRedirect();
        $id = DB::table('classes')->value('id');
        $this->get($this->base.'/'.$id)->assertOk()->assertSee('Nursery');
        $this->get($this->base.'/'.$id.'/edit')->assertOk();
        $this->put($this->base.'/'.$id, $this->data(['name' => 'LKG', 'code' => 'LKG', 'sort_order' => 10]))->assertRedirect();
        $this->assertDatabaseHas('classes', ['id' => $id, 'school_id' => $admin->school_id, 'name' => 'LKG', 'code' => 'LKG', 'sort_order' => 10, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CLASS_CREATED', 'entity_id' => $id, 'module' => 'academics']);
        $audit = DB::table('audit_logs')->where('action', 'CLASS_UPDATED')->where('entity_id', $id)->first();
        $this->assertSame('Nursery', json_decode($audit->old_values, true)['name']);
        $this->assertSame(10, json_decode($audit->new_values, true)['sort_order']);
        app(CurrentSchool::class)->set($admin->school);
        $this->assertTrue($admin->school->classes()->whereKey($id)->exists());
    }

    public function test_validation_rejects_invalid_fields_and_direct_status_changes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post($this->base, $this->data(['name' => '', 'code' => 'bad code', 'sort_order' => -1, 'school_id' => 999, 'status' => 'INACTIVE', 'academic_year_id' => 1, 'section_id' => 1]))->assertSessionHasErrors(['name', 'sort_order', 'school_id', 'status', 'academic_year_id', 'section_id']);
        foreach (['1.5', '4294967296', 'invalid'] as $order) {
            $this->post($this->base, $this->data(['sort_order' => $order]))->assertSessionHasErrors('sort_order');
        }
        $this->assertDatabaseCount('classes', 0);
        $this->post($this->base, $this->data(['name' => '1', 'code' => 'C1']))->assertRedirect();
        $this->assertDatabaseHas('classes', ['name' => '1', 'sort_order' => 0]);
    }

    public function test_names_and_codes_are_unique_within_school_including_inactive_classes(): void
    {
        $admin = $this->admin();
        $existing = $this->schoolClass($admin, ['name' => 'Nursery', 'code' => 'NURSERY', 'status' => 'INACTIVE']);
        $other = User::factory()->create();
        $this->schoolClass($other, ['name' => 'Nursery', 'code' => 'NURSERY']);
        $this->actingAs($admin)->post($this->base, $this->data(['code' => 'NEW']))->assertSessionHasErrors('name');
        $this->post($this->base, $this->data(['name' => 'Nursery!']))->assertSessionHasErrors('code');
        $this->put($this->base.'/'.$existing->id, $this->data())->assertRedirect();
        $this->assertDatabaseCount('classes', 2);
    }

    public function test_listing_uses_numeric_order_and_then_name_for_ties(): void
    {
        $admin = $this->admin();
        $last = $this->schoolClass($admin, ['name' => 'Ten', 'sort_order' => 10]);
        $middleB = $this->schoolClass($admin, ['name' => 'Beta', 'sort_order' => 2]);
        $first = $this->schoolClass($admin, ['name' => 'First', 'sort_order' => 0]);
        $middleA = $this->schoolClass($admin, ['name' => 'Alpha', 'sort_order' => 2]);
        $this->actingAs($admin)->get($this->base)->assertOk()->assertViewHas('classes', fn ($rows) => $rows->pluck('id')->all() === [$first->id, $middleA->id, $middleB->id, $last->id]);
    }

    public function test_search_status_filters_and_pagination_preserve_school_scope(): void
    {
        $admin = $this->admin();
        $one = $this->schoolClass($admin, ['name' => 'Nursery', 'code' => 'NUR0']);
        $two = $this->schoolClass($admin, ['name' => 'LKG', 'code' => 'LKG', 'status' => 'INACTIVE']);
        $this->actingAs($admin)->get($this->base.'?q=0&status=ACTIVE')->assertOk()->assertSee($one->code)->assertDontSee($two->code);
        $this->get($this->base.'?status=INACTIVE')->assertOk()->assertSee($two->code)->assertDontSee($one->code);
        $this->get($this->base.'?q=Nursery')->assertOk()->assertSee($one->code)->assertDontSee($two->code);
        $this->get($this->base.'?status=INVALID')->assertSessionHasErrors('status');
        app(CurrentSchool::class)->set($admin->school);
        SchoolClass::factory()->count(23)->sequence(fn ($sequence) => ['name' => 'Match '.$sequence->index])->create();
        $this->get($this->base.'?q=Match')->assertOk()->assertViewHas('classes', fn ($rows) => $rows->count() === 20 && $rows->total() === 23 && str_contains($rows->url(2), 'q=Match'));
    }

    public function test_view_only_class_permission_prevents_mutations(): void
    {
        $admin = $this->admin();
        $schoolClass = $this->schoolClass($admin);
        $reader = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'class.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $reader, [$role->id]);
        $url = $this->base.'/'.$schoolClass->id;
        $this->actingAs($reader)->get($this->base)->assertOk()->assertDontSee('Add class');
        $this->get($url)->assertOk()->assertDontSee('Edit class')->assertDontSee('Confirm deactivation');
        $this->get($this->base.'/create')->assertForbidden();
        $this->get($url.'/edit')->assertForbidden();
        $this->post($this->base, $this->data())->assertForbidden();
        $this->put($url, $this->data())->assertForbidden();
        $this->post($url.'/activate')->assertForbidden();
        $this->post($url.'/deactivate')->assertForbidden();
    }

    public function test_class_management_does_not_require_academic_year_permissions(): void
    {
        $admin = $this->admin();
        $manager = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        $ids = DB::table('permissions')->whereIn('name', ['class.view', 'class.manage'])->pluck('id')->all();
        app(AccessManager::class)->setPermissions($admin, $role, $ids);
        app(AccessManager::class)->assignRoles($admin, $manager, [$role->id]);
        $this->actingAs($manager)->get($this->base.'/create')->assertOk();
        $this->post($this->base, $this->data())->assertRedirect();
        $this->get('/academics/academic-years')->assertForbidden();
    }

    public function test_foreign_class_ids_return_404_and_are_hidden_from_listing(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $foreign = $this->schoolClass($other, ['name' => 'FOREIGN_SECRET']);
        $url = $this->base.'/'.$foreign->id;
        $this->actingAs($admin)->get($this->base)->assertOk()->assertDontSee('FOREIGN_SECRET');
        $this->get($url)->assertNotFound();
        $this->get($url.'/edit')->assertNotFound();
        $this->put($url, $this->data())->assertNotFound();
        $this->post($url.'/activate')->assertNotFound();
        $this->post($url.'/deactivate')->assertNotFound();
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(SchoolClassService::class)->save($admin, $foreign, $this->data());
    }

    public function test_activation_changes_preserve_records_and_are_audited_once(): void
    {
        $admin = $this->admin();
        $schoolClass = $this->schoolClass($admin);
        $url = $this->base.'/'.$schoolClass->id;
        $this->actingAs($admin)->post($url.'/deactivate')->assertRedirect();
        $this->post($url.'/deactivate')->assertRedirect();
        $this->assertDatabaseHas('classes', ['id' => $schoolClass->id, 'status' => 'INACTIVE']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'CLASS_DEACTIVATED')->where('entity_id', $schoolClass->id)->count());
        $this->post($url.'/activate')->assertRedirect();
        $this->assertDatabaseHas('classes', ['id' => $schoolClass->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CLASS_ACTIVATED', 'entity_id' => $schoolClass->id]);
        $this->delete($url)->assertStatus(405);
        $this->assertDatabaseCount('classes', 1);
    }

    public function test_database_enforces_school_code_uniqueness(): void
    {
        $admin = $this->admin();
        $this->schoolClass($admin, ['code' => 'NUR']);
        $this->expectException(QueryException::class);
        DB::table('classes')->insert(['school_id' => $admin->school_id, 'name' => 'Other', 'code' => 'NUR']);
    }

    public function test_database_enforces_school_name_uniqueness(): void
    {
        $admin = $this->admin();
        $this->schoolClass($admin, ['name' => 'Nursery']);
        $this->expectException(QueryException::class);
        DB::table('classes')->insert(['school_id' => $admin->school_id, 'name' => 'Nursery', 'code' => 'OTHER']);
    }

    public function test_database_rejects_unknown_class_status(): void
    {
        $admin = $this->admin();
        $this->expectException(QueryException::class);
        DB::table('classes')->insert(['school_id' => $admin->school_id, 'name' => 'Nursery', 'code' => 'NUR', 'status' => 'UNKNOWN']);
    }

    public function test_audit_failure_rolls_back_creation_and_status_changes(): void
    {
        $admin = $this->admin();
        $existing = $this->schoolClass($admin);
        $this->mock(AuditLogger::class)->shouldReceive('record')->twice()->andThrow(new \RuntimeException('Audit failed'));
        try {
            app(SchoolClassService::class)->save($admin, null, $this->data());
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('classes', 1);
        try {
            app(SchoolClassService::class)->setStatus($admin, $existing, ClassStatus::Inactive);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseHas('classes', ['id' => $existing->id, 'status' => 'ACTIVE']);
    }
}

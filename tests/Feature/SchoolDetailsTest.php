<?php

namespace Tests\Feature;

use App\Actions\UpdateSchoolDetails;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('DEMO', 'Demo School', $user->email);

        return $user->fresh();
    }

    private function data(array $extra = []): array
    {
        return array_merge(['code' => 'DEMO', 'name' => 'Updated School', 'timezone' => 'Asia/Kolkata'], $extra);
    }

    public function test_guests_and_unprivileged_users_cannot_access_settings(): void
    {
        $this->get('/settings/school')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/settings/school')->assertForbidden();
        $this->put('/settings/school', $this->data())->assertForbidden();
    }

    public function test_administrator_can_view_and_update_school_with_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/settings/school')->assertOk()->assertSee('Demo School')->assertSee('Save school details');
        $this->put('/settings/school', $this->data(['email' => 'office@example.test', 'city' => 'New Tehri', 'short_name' => 'DS']))->assertRedirect('/settings/school')->assertSessionHas('status');
        $school = $admin->school()->first();
        $this->assertSame('Updated School', $school->name);
        $this->assertSame('office@example.test', $school->email);
        app(CurrentSchool::class)->set($school);
        $log = AuditLog::where('action', 'SCHOOL_DETAILS_UPDATED')->firstOrFail();
        $this->assertSame('school', $log->module);
        $this->assertSame('Demo School', $log->old_values['name']);
        $this->assertSame('Updated School', $log->new_values['name']);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_view_only_user_cannot_update(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['school_id' => $admin->school_id]);
        app(CurrentSchool::class)->set($admin->school);
        $role = Role::where('name', 'RECEPTIONIST')->firstOrFail();
        app(AccessManager::class)->setPermissions($admin, $role, [DB::table('permissions')->where('name', 'school.view')->value('id')]);
        app(AccessManager::class)->assignRoles($admin, $user, [$role->id]);
        $this->actingAs($user)->get('/settings/school')->assertOk()->assertDontSee('Save school details');
        $this->put('/settings/school', $this->data())->assertForbidden();
        $this->assertSame('Demo School', $admin->school()->first()->name);
    }

    public function test_validation_rejects_invalid_and_sensitive_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings/school', $this->data(['code' => 'spaces not allowed', 'name' => '', 'email' => 'bad-email', 'timezone' => 'Invalid/Zone', 'school_id' => 999, 'is_active' => false, 'logo_path' => 'arbitrary']))->assertSessionHasErrors(['code', 'name', 'email', 'timezone', 'school_id', 'is_active', 'logo_path']);
        $this->assertSame('Demo School', $admin->school()->first()->name);
    }

    public function test_school_codes_are_unique(): void
    {
        $admin = $this->admin();
        School::factory()->create(['code' => 'TAKEN']);
        $this->actingAs($admin)->put('/settings/school', $this->data(['code' => 'TAKEN']))->assertSessionHasErrors('code');
    }

    public function test_submitted_foreign_school_id_cannot_change_either_school(): void
    {
        $admin = $this->admin();
        $foreign = School::factory()->create();
        $this->actingAs($admin)->put('/settings/school', $this->data(['school_id' => $foreign->id]))->assertSessionHasErrors('school_id');
        $this->assertSame('Demo School', $admin->school()->first()->name);
        $this->assertSame($foreign->name, $foreign->fresh()->name);
        $this->get('/settings/school/'.$foreign->id)->assertNotFound();
    }

    public function test_action_rejects_a_foreign_school(): void
    {
        $admin = $this->admin();
        app(CurrentSchool::class)->set($admin->school);
        $this->expectException(AuthorizationException::class);
        app(UpdateSchoolDetails::class)->execute($admin, School::factory()->create(), $this->data());
    }

    public function test_logo_is_private_and_restricted_to_current_school(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings/school', $this->data(['logo' => UploadedFile::fake()->image('crest.png', 200, 200)]))->assertRedirect('/settings/school');
        $path = $admin->school()->first()->logo_path;
        Storage::disk('local')->assertExists($path);
        $this->get('/school/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create())->get('/school/logo')->assertNotFound();
        $this->post('/logout');
        $this->get('/school/logo')->assertRedirect('/login');
    }

    public function test_invalid_logo_formats_sizes_and_dimensions_are_rejected(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin);
        foreach ([UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->image('large.png')->size(2049), UploadedFile::fake()->image('wide.png', 2001, 20), UploadedFile::fake()->create('fake.png', 2, 'text/plain')] as $logo) {
            $this->put('/settings/school', $this->data(['logo' => $logo]))->assertSessionHasErrors('logo');
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_replacing_logo_retains_previous_file_and_empty_upload_keeps_current_logo(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings/school', $this->data(['logo' => UploadedFile::fake()->image('one.jpg')]));
        $old = $admin->school()->first()->logo_path;
        $this->put('/settings/school', $this->data(['logo' => UploadedFile::fake()->image('two.png')]));
        $new = $admin->school()->first()->logo_path;
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertExists($old);
        Storage::disk('local')->assertExists($new);
        $this->put('/settings/school', $this->data());
        $this->assertSame($new, $admin->school()->first()->logo_path);
    }

    public function test_failed_audit_rolls_back_details_and_cleans_up_new_upload(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        app(CurrentSchool::class)->set($admin->school);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(UpdateSchoolDetails::class)->execute($admin, $admin->school, $this->data(), UploadedFile::fake()->image('crest.png'));
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('Demo School', $admin->school()->first()->name);
        $this->assertNull($admin->school()->first()->logo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

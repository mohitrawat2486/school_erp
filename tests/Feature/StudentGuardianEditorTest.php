<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\ProvisionSchool;
use App\Services\StudentGuardianService;
use App\Services\StudentService;
use App\Support\CurrentSchool;
use App\Support\GuardianEditorState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentGuardianEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('EDITOR', 'Editor School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    private function studentData(array $extra = []): array
    {
        return array_replace(['aadhaar_number' => '234567890123', 'first_name' => 'Aarav', 'last_name' => 'Sharma', 'admission_number' => 'ADM-001', 'admission_date' => '2026-04-01', 'address' => '12 School Road'], $extra);
    }

    private function draft(string $name = 'Anita'): array
    {
        return ['kind' => 'new', 'details' => ['first_name' => $name, 'last_name' => 'Sharma', 'phone' => '9876543210', 'same_address' => true], 'relationship' => 'GUARDIAN', 'is_emergency_contact' => true];
    }

    private function existing(Guardian $guardian, array $extra = []): array
    {
        return array_replace(['kind' => 'existing', 'guardian_id' => $guardian->id, 'revision' => GuardianEditorState::contact($guardian->fresh()), 'edit_contact' => false, 'relationship' => 'GUARDIAN', 'is_emergency_contact' => true], $extra);
    }

    private function payload(array $rows, ?Student $student = null, array $extra = []): array
    {
        return $this->studentData(['guardians_present' => '1', 'guardians' => $rows, 'primary_guardian' => $rows ? (string) array_key_first($rows) : null] + ($student ? ['guardian_setup_revision' => GuardianEditorState::student($student->fresh())] : []) + $extra);
    }

    private function link(User $admin, Student $student, Guardian $guardian, bool $primary = true): StudentGuardian
    {
        return app(StudentGuardianService::class)->save($admin, $student, $guardian, ['relationship' => 'GUARDIAN', 'is_primary' => $primary, 'is_emergency_contact' => true]);
    }

    public function test_creates_up_to_three_contacts_with_one_primary_and_shared_address(): void
    {
        $admin = $this->admin();
        $data = $this->payload([0 => $this->draft('Anita'), 1 => $this->draft('Ravi'), 2 => $this->draft('Meera')]);
        $data['primary_guardian'] = '1';
        $this->actingAs($admin)->post('/students', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('guardians', 3);
        $this->assertDatabaseCount('student_guardians', 3);
        $this->assertSame(1, DB::table('student_guardians')->where('is_primary', true)->count());
        $primary = DB::table('guardians')->where('first_name', 'Ravi')->value('id');
        $this->assertDatabaseHas('student_guardians', ['guardian_id' => $primary, 'is_primary' => true]);
        $this->assertSame(3, DB::table('guardians')->where('address', '12 School Road')->count());
    }

    public function test_cap_duplicate_contacts_and_missing_primary_are_validated(): void
    {
        $admin = $this->admin();
        $guardian = Guardian::factory()->create();
        $this->actingAs($admin)->post('/students', $this->payload(array_fill(0, 4, $this->draft())))->assertSessionHasErrors('guardians');
        app(CurrentSchool::class)->set($admin->school);
        $rows = [$this->existing($guardian), $this->existing($guardian)];
        $this->post('/students', $this->payload($rows))->assertSessionHasErrors('guardians.1.guardian_id');
        $data = $this->payload([$this->draft()]);
        $data['primary_guardian'] = '999';
        $this->post('/students', $data)->assertSessionHasErrors('primary_guardian');
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('student_guardians', 0);
    }

    public function test_edit_saves_guardian_only_changes_without_requiring_student_field_changes(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create($this->studentData());
        $one = Guardian::factory()->create();
        $two = Guardian::factory()->create();
        $this->link($admin, $student, $one);
        $this->link($admin, $student, $two, false);
        $data = $this->payload([$this->existing($one, ['relationship' => 'MOTHER']), $this->existing($two, ['is_emergency_contact' => false])], $student);
        $data['primary_guardian'] = '1';
        $this->actingAs($admin)->put('/students/'.$student->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_guardians', ['student_id' => $student->id, 'guardian_id' => $one->id, 'relationship' => 'MOTHER', 'is_primary' => false]);
        $this->assertDatabaseHas('student_guardians', ['student_id' => $student->id, 'guardian_id' => $two->id, 'is_primary' => true, 'is_emergency_contact' => false]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'STUDENT_UPDATED', 'entity_id' => $student->id]);
    }

    public function test_shared_details_change_only_with_explicit_edit_and_preserve_sibling_links(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create($this->studentData());
        $sibling = Student::factory()->create();
        $guardian = Guardian::factory()->create(['phone' => 'ORIGINAL']);
        $this->link($admin, $student, $guardian);
        $this->link($admin, $sibling, $guardian);
        $row = $this->existing($guardian, ['details' => ['first_name' => 'Ignored', 'phone' => 'IGNORED', 'same_address' => false]]);
        $this->actingAs($admin)->put('/students/'.$student->id, $this->payload([$row], $student))->assertRedirect()->assertSessionHasNoErrors();
        app(CurrentSchool::class)->set($admin->school);
        $this->assertSame('ORIGINAL', $guardian->fresh()->phone);
        $row = $this->existing($guardian, ['edit_contact' => true, 'details' => ['first_name' => 'Anita', 'phone' => 'UPDATED', 'same_address' => false, 'address' => 'Own address']]);
        $this->put('/students/'.$student->id, $this->payload([$row], $student))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id, 'phone' => 'UPDATED', 'address' => 'Own address']);
        $this->assertDatabaseHas('student_guardians', ['student_id' => $sibling->id, 'guardian_id' => $guardian->id, 'status' => 'ACTIVE', 'is_primary' => true]);
        $this->assertDatabaseCount('guardians', 1);
    }

    public function test_removal_requires_confirmation_retains_history_and_reuses_existing_link_identity(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create($this->studentData());
        $guardian = Guardian::factory()->create();
        $link = $this->link($admin, $student, $guardian);
        $data = $this->payload([], $student);
        $this->actingAs($admin)->put('/students/'.$student->id, $data)->assertSessionHasErrors('confirm_guardian_removals');
        $this->assertDatabaseHas('student_guardians', ['id' => $link->id, 'status' => 'ACTIVE']);
        $data['confirm_guardian_removals'] = true;
        $this->put('/students/'.$student->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_guardians', ['id' => $link->id, 'status' => 'INACTIVE', 'is_primary' => false]);
        app(CurrentSchool::class)->set($admin->school);
        $this->put('/students/'.$student->id, $this->payload([$this->existing($guardian)], $student))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_guardians', ['id' => $link->id, 'status' => 'ACTIVE', 'is_primary' => true]);
        $this->assertDatabaseCount('student_guardians', 1);
    }

    public function test_replacement_at_capacity_removes_before_adding(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create($this->studentData());
        $contacts = Guardian::factory()->count(3)->create();
        foreach ($contacts as $i => $contact) {
            $this->link($admin, $student, $contact, $i === 0);
        }
        $data = $this->payload([$this->existing($contacts[1]), $this->existing($contacts[2]), $this->draft('Replacement')], $student);
        $data['confirm_guardian_removals'] = true;
        $this->actingAs($admin)->put('/students/'.$student->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('student_guardians')->where('status', 'ACTIVE')->count());
        $this->assertDatabaseHas('student_guardians', ['guardian_id' => $contacts[0]->id, 'status' => 'INACTIVE']);
    }

    public function test_existing_linking_route_cannot_bypass_three_contact_limit(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $contacts = Guardian::factory()->count(4)->create();
        foreach ($contacts->take(3) as $i => $contact) {
            $this->link($admin, $student, $contact, $i === 0);
        }
        $this->actingAs($admin)->put('/students/'.$student->id.'/guardians/'.$contacts[3]->id, ['relationship' => 'GUARDIAN', 'is_primary' => false, 'is_emergency_contact' => true])->assertSessionHasErrors('guardian');
        $this->assertDatabaseCount('student_guardians', 3);
    }

    public function test_stale_student_or_shared_contact_revision_blocks_overwrites(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create($this->studentData());
        $guardian = Guardian::factory()->create();
        $this->link($admin, $student, $guardian);
        $data = $this->payload([$this->existing($guardian)], $student);
        $guardian->update(['phone' => 'CHANGED']);
        $this->actingAs($admin)->put('/students/'.$student->id, $data)->assertSessionHasErrors('guardian_setup_revision');
        app(CurrentSchool::class)->set($admin->school);
        $other = Guardian::factory()->create();
        $data = $this->payload([$this->existing($guardian), $this->existing($other)], $student);
        $other->update(['phone' => 'NEW NUMBER']);
        $this->put('/students/'.$student->id, $data)->assertSessionHasErrors('guardians.1.revision');
        $this->assertDatabaseCount('student_guardians', 1);
    }

    public function test_inactive_and_foreign_contacts_are_not_newly_linked(): void
    {
        $admin = $this->admin();
        $inactive = Guardian::factory()->create(['status' => 'INACTIVE']);
        $inactiveRow = $this->existing($inactive);
        $foreignUser = User::factory()->create();
        app(CurrentSchool::class)->set($foreignUser->school);
        $foreign = Guardian::factory()->create();
        $foreignRow = $this->existing($foreign);
        app(CurrentSchool::class)->set($admin->school);
        $this->actingAs($admin)->post('/students', $this->payload([$inactiveRow]))->assertSessionHasErrors('guardians.0.guardian_id');
        $this->post('/students', $this->payload([$foreignRow]))->assertSessionHasErrors('guardians.0.guardian_id');
        $this->assertDatabaseCount('students', 0);
    }

    public function test_existing_over_limit_contacts_are_preserved_but_new_additions_are_blocked(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create($this->studentData());
        $contacts = Guardian::factory()->count(4)->create();
        foreach ($contacts as $i => $contact) {
            (new StudentGuardian)->forceFill(['student_id' => $student->id, 'guardian_id' => $contact->id, 'relationship' => 'GUARDIAN', 'is_primary' => $i === 0, 'is_emergency_contact' => true])->save();
        }
        $rows = $contacts->map(fn ($guardian) => $this->existing($guardian))->all();
        $this->actingAs($admin)->put('/students/'.$student->id, $this->payload($rows, $student))->assertRedirect()->assertSessionHasNoErrors();
        app(CurrentSchool::class)->set($admin->school);
        $rows[3] = $this->draft();
        $this->put('/students/'.$student->id, $this->payload($rows, $student))->assertSessionHasErrors('guardians');
        $this->assertSame(4, DB::table('student_guardians')->where('status', 'ACTIVE')->count());
    }

    public function test_audit_failure_rolls_back_all_edits_and_new_photo(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $student = Student::factory()->create($this->studentData());
        $guardian = Guardian::factory()->create();
        $link = $this->link($admin, $student, $guardian);
        $data = $this->payload([$this->draft('New guardian')], $student);
        $data['confirm_guardian_removals'] = true;
        $real = new AuditLogger;
        $before = DB::table('audit_logs')->count();
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($real) {
            if ($args[1] === 'STUDENT_GUARDIAN_LINKED') {
                throw new \RuntimeException('Failed link audit');
            }

            return $real->record(...$args);
        });
        try {
            app(StudentService::class)->save($admin, $student, $data, UploadedFile::fake()->image('student.png'));
            $this->fail('Expected audit rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failed link audit', $exception->getMessage());
        }
        $this->assertDatabaseHas('student_guardians', ['id' => $link->id, 'status' => 'ACTIVE', 'is_primary' => true]);
        $this->assertDatabaseCount('guardians', 1);
        $this->assertDatabaseCount('audit_logs', $before);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_editor_form_is_inline_and_update_only_staff_can_search_without_create_permission(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create();
        $guardian = Guardian::factory()->create();
        $this->link($admin, $student, $guardian);
        $user = User::factory()->create(['school_id' => $admin->school_id]);
        $role = Role::create(['name' => 'EDITOR-ONLY']);
        app(AccessManager::class)->setPermissions($admin, $role, DB::table('permissions')->whereIn('name', ['student.view', 'student.update'])->pluck('id')->all());
        app(AccessManager::class)->assignRoles($admin, $user, [$role->id]);
        $this->actingAs($user)->get('/students/'.$student->id.'/edit')->assertOk()->assertSee('data-guardian-editor', false)->assertSee($guardian->fullName())->assertDontSee('Manage guardian contacts');
        $this->getJson('/students/guardian-editor-options?student_id='.$student->id)->assertOk()->assertJsonPath('guardians.0.id', $guardian->id);
        $this->getJson('/students/guardian-editor-options')->assertForbidden();
        $this->get('/students/create')->assertForbidden();
    }

    public function test_validation_restores_multiple_drafts_and_the_affected_card_errors(): void
    {
        $admin = $this->admin();
        $rows = [$this->draft('Anita'), $this->draft('Ravi')];
        $rows[1]['details']['phone'] = '';
        $this->actingAs($admin)->from('/students/create')->post('/students', $this->payload($rows))
            ->assertSessionHasErrors('guardians.1.details.phone')->assertSessionHasInput('guardians.0.details.first_name', 'Anita');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/students/create')->assertOk()
            ->assertSee('Anita')->assertSee('Ravi')->assertSee('data-guardian-card', false)->assertSee('field is required');
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('guardians', 0);
    }

    public function test_non_editor_saves_keep_contacts_and_repeated_unchanged_editor_saves_do_not_add_audits(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create($this->studentData());
        $guardian = Guardian::factory()->create();
        $this->link($admin, $student, $guardian);
        $before = DB::table('audit_logs')->count();
        $this->actingAs($admin)->put('/students/'.$student->id, $this->studentData())->assertRedirect()->assertSessionHasNoErrors();
        app(CurrentSchool::class)->set($admin->school);
        $payload = $this->payload([$this->existing($guardian)], $student);
        $this->put('/students/'.$student->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->put('/students/'.$student->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_guardians', ['guardian_id' => $guardian->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('audit_logs', $before);
    }

    public function test_editor_search_is_bounded_and_does_not_expose_other_schools_or_inactive_contacts(): void
    {
        $admin = $this->admin();
        Guardian::factory()->count(23)->create(['first_name' => 'Contact']);
        Guardian::factory()->create(['first_name' => 'PRIVATE INACTIVE', 'status' => 'INACTIVE']);
        $foreign = User::factory()->create();
        app(CurrentSchool::class)->set($foreign->school);
        Guardian::factory()->create(['first_name' => 'PRIVATE FOREIGN']);
        $foreignStudent = Student::factory()->create();
        app(CurrentSchool::class)->set($admin->school);
        $this->actingAs($admin)->getJson('/students/guardian-editor-options')->assertOk()->assertJsonCount(20, 'guardians')
            ->assertJsonPath('has_more', true)->assertDontSee('PRIVATE INACTIVE')->assertDontSee('PRIVATE FOREIGN');
        $this->getJson('/students/guardian-editor-options?q=missing')->assertOk()->assertJsonCount(0, 'guardians');
        $this->getJson('/students/guardian-editor-options?student_id='.$foreignStudent->id)->assertNotFound();
        $this->getJson('/students/guardian-editor-options?'.http_build_query(['q' => ['invalid']]))->assertUnprocessable();
        $this->post('/logout');
        $this->get('/students/guardian-editor-options')->assertRedirect('/login');
    }
}

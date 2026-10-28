<?php

namespace Tests\Feature;

use App\Enums\GradingSchemeStatus;
use App\Models\GradeRule;
use App\Models\GradingScheme;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\GradeResolver;
use App\Services\GradingSchemeService;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GradingTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $r = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($s = auth()->user()?->school) {
            app(CurrentSchool::class)->set($s);
        }

        return $r;
    }

    private function admin(string $code = 'GRADE'): User
    {
        $u = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run($code, 'Grading School', $u->email);
        $u = $u->fresh();
        app(CurrentSchool::class)->set($u->school);

        return $u;
    }

    private function scheme(User $u, string $name = 'Standard'): GradingScheme
    {
        return app(GradingSchemeService::class)->save($u, null, ['name' => $name]);
    }

    private function rule(User $u, GradingScheme $s, array $extra = []): GradeRule
    {
        return app(GradingSchemeService::class)->saveRule($u, $s, null, array_replace(['version' => $s->fresh()->lock_version, 'min_percentage' => 0, 'max_percentage' => 100, 'grade' => 'Pass', 'grade_point' => null, 'remarks' => null], $extra));
    }

    private function url(GradingScheme $s): string
    {
        return '/examinations/grading-schemes/'.$s->id;
    }

    private function ready(User $u): GradingScheme
    {
        $s = $this->scheme($u);
        $this->rule($u, $s, ['min_percentage' => 0, 'max_percentage' => '49.99', 'grade' => 'Needs work']);
        $this->rule($u, $s, ['min_percentage' => 50, 'max_percentage' => 100, 'grade' => 'Achieved', 'grade_point' => '4.00']);
        app(GradingSchemeService::class)->setStatus($u, $s, GradingSchemeStatus::Active, ['version' => $s->fresh()->lock_version]);

        return $s->fresh();
    }

    private function user(User $admin, array $permissions): User
    {
        $u = User::factory()->create(['school_id' => $admin->school_id]);
        $r = Role::create(['name' => fake()->unique()->bothify('GRADE-####')]);
        app(AccessManager::class)->setPermissions($admin, $r, DB::table('permissions')->whereIn('name', $permissions)->pluck('id')->all());
        app(AccessManager::class)->assignRoles($admin, $u, [$r->id]);

        return $u;
    }

    public function test_scheme_crud_and_rule_create_edit_remove_audits(): void
    {
        $u = $this->admin();
        $this->actingAs($u)->get('/examinations/grading-schemes/create')->assertOk();
        $this->post('/examinations/grading-schemes', ['name' => 'Custom'])->assertSessionHasNoErrors();
        $s = GradingScheme::first();
        $this->assertSame('INACTIVE', $s->status->value);
        $this->get($this->url($s))->assertOk();
        $this->get($this->url($s).'/edit')->assertOk();
        $this->put($this->url($s), ['name' => 'Renamed', 'version' => 1])->assertSessionHasNoErrors();
        $this->get($this->url($s).'/rules/create')->assertOk();
        $this->post($this->url($s).'/rules', ['version' => 2, 'min_percentage' => 0, 'max_percentage' => 100, 'grade' => 'Custom A', 'grade_point' => '9.75', 'remarks' => 'Excellent'])->assertSessionHasNoErrors();
        $r = GradeRule::first();
        $this->get($this->url($s).'/rules/'.$r->id.'/edit')->assertOk();
        $this->put($this->url($s).'/rules/'.$r->id, ['version' => 3, 'min_percentage' => 0, 'max_percentage' => 100, 'grade' => 'Custom B'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grade_rules', ['id' => $r->id, 'grade' => 'Custom B', 'grade_point' => null, 'remarks' => null]);
        $this->delete($this->url($s).'/rules/'.$r->id, ['version' => 4])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('grade_rules', 0);
        foreach (['GRADING_SCHEME_CREATED', 'GRADING_SCHEME_UPDATED', 'GRADE_RULE_CREATED', 'GRADE_RULE_UPDATED', 'GRADE_RULE_DELETED'] as $a) {
            $this->assertDatabaseHas('audit_logs', ['action' => $a]);
        }
    }

    public function test_active_scheme_lookup_uses_database_labels_and_exact_boundaries(): void
    {
        $u = $this->admin();
        $s = $this->ready($u);
        foreach (['0', '0.01', '49.99'] as $p) {
            $this->assertSame('Needs work', app(GradeResolver::class)->resolve($s, $p)->grade);
        }
        foreach (['50', '50.01', '99.99', '100'] as $p) {
            $this->assertSame('Achieved', app(GradeResolver::class)->resolve($s, $p)->grade);
        }
        $this->actingAs($u)->get($this->url($s).'?percentage=50')->assertOk()->assertSee('Achieved')->assertSee('4.00');
    }

    public function test_activation_requires_full_contiguous_coverage(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->actingAs($u);
        $this->post($this->url($s).'/activate', ['version' => 1])->assertSessionHasErrors('rules');
        $r = $this->rule($u, $s, ['min_percentage' => 1, 'max_percentage' => 100]);
        $this->post($this->url($s).'/activate', ['version' => 2])->assertSessionHasErrors('rules');
        app(GradingSchemeService::class)->saveRule($u, $s, $r, ['version' => 2, 'min_percentage' => 0, 'max_percentage' => 99, 'grade' => 'Pass']);
        $this->post($this->url($s).'/activate', ['version' => 3])->assertSessionHasErrors('rules');
    }

    public function test_integer_example_gaps_are_rejected_until_decimal_gap_is_filled(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->rule($u, $s, ['min_percentage' => 0, 'max_percentage' => 32, 'grade' => 'E']);
        $r = $this->rule($u, $s, ['min_percentage' => 33, 'max_percentage' => 100, 'grade' => 'D']);
        $this->actingAs($u)->post($this->url($s).'/activate', ['version' => 3])->assertSessionHasErrors('rules');
        app(GradingSchemeService::class)->saveRule($u, $s, $r, ['version' => 3, 'min_percentage' => '32.01', 'max_percentage' => 100, 'grade' => 'D']);
        $this->post($this->url($s).'/activate', ['version' => 4])->assertSessionHasNoErrors();
        $this->assertSame('D', app(GradeResolver::class)->resolve($s, '32.01')->grade);
    }

    public function test_overlap_shared_endpoint_and_duplicate_grade_fail(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->rule($u, $s, ['max_percentage' => 50, 'grade' => 'A']);
        $this->actingAs($u);
        foreach ([['min_percentage' => 50, 'max_percentage' => 100, 'grade' => 'B'], ['min_percentage' => 25, 'max_percentage' => 40, 'grade' => 'C'], ['min_percentage' => 0, 'max_percentage' => 100, 'grade' => 'D'], ['min_percentage' => '50.01', 'max_percentage' => 100, 'grade' => 'a']] as $data) {
            $this->post($this->url($s).'/rules', $data + ['version' => 2])->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('grade_rules', 1);
    }

    public function test_invalid_rule_values_precision_labels_and_overrides(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->actingAs($u);
        $base = ['version' => 1, 'min_percentage' => 0, 'max_percentage' => 100, 'grade' => 'A'];
        foreach ([['min_percentage' => -1], ['max_percentage' => 101], ['min_percentage' => '0.001'], ['min_percentage' => 90, 'max_percentage' => 80], ['grade' => ' '], ['grade' => str_repeat('a', 21)], ['grade_point' => -1], ['grade_point' => '4.001'], ['grade_point' => 1000], ['remarks' => str_repeat('x', 501)], ['school_id' => 999], ['grading_scheme_id' => 999], ['status' => 'ACTIVE']] as $bad) {
            $this->post($this->url($s).'/rules', array_replace($base, $bad))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('grade_rules', 0);
    }

    public function test_active_configuration_is_frozen_until_deactivation(): void
    {
        $u = $this->admin();
        $s = $this->ready($u);
        $r = $s->rules()->first();
        $this->actingAs($u);
        $this->get($this->url($s).'/edit')->assertForbidden();
        $this->get($this->url($s).'/rules/create')->assertForbidden();
        $this->put($this->url($s), ['version' => 4, 'name' => 'Changed'])->assertSessionHasErrors();
        $this->delete($this->url($s).'/rules/'.$r->id, ['version' => 4])->assertSessionHasErrors();
        $this->get($this->url($s))->assertOk()->assertDontSee('Add grade rule');
        $this->post($this->url($s).'/deactivate', ['version' => 4])->assertSessionHasNoErrors();
        $this->put($this->url($s), ['version' => 5, 'name' => 'Changed'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grading_schemes', ['name' => 'Changed', 'status' => 'INACTIVE']);
    }

    public function test_stale_scheme_and_rule_mutations_are_rejected(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->actingAs($u);
        $r = $this->rule($u, $s);
        $this->put($this->url($s), ['version' => 1, 'name' => 'Stale'])->assertSessionHasErrors('version');
        $this->post($this->url($s).'/activate', ['version' => 1])->assertSessionHasErrors('version');
        $this->delete($this->url($s).'/rules/'.$r->id, ['version' => 1])->assertSessionHasErrors('version');
        $this->assertDatabaseHas('grading_schemes', ['name' => 'Standard', 'lock_version' => 2]);
    }

    public function test_noop_saves_and_status_do_not_duplicate_audits(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $r = $this->rule($u, $s);
        $count = DB::table('audit_logs')->count();
        app(GradingSchemeService::class)->save($u, $s, ['version' => 2, 'name' => 'Standard']);
        app(GradingSchemeService::class)->saveRule($u, $s, $r, ['version' => 2] + $r->only(GradeRule::FIELDS));
        app(GradingSchemeService::class)->setStatus($u, $s, GradingSchemeStatus::Inactive, ['version' => 2]);
        $this->assertSame($count, DB::table('audit_logs')->count());
        $this->assertSame(2, $s->fresh()->lock_version);
    }

    public function test_nested_rule_cannot_be_moved_to_another_scheme(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $r = $this->rule($u, $s);
        $other = $this->scheme($u, 'Other');
        $this->actingAs($u)->get($this->url($other).'/rules/'.$r->id.'/edit')->assertNotFound();
        $this->delete($this->url($other).'/rules/'.$r->id, ['version' => 1])->assertNotFound();
        $this->put($this->url($other).'/rules/'.$r->id, ['version' => 1, 'min_percentage' => 0, 'max_percentage' => 100, 'grade' => 'X'])->assertNotFound();
    }

    public function test_scheme_names_unique_trimmed_and_identity_protected(): void
    {
        $u = $this->admin();
        $this->scheme($u, 'Custom');
        $this->actingAs($u);
        foreach ([['name' => ' custom '], ['name' => ' '], ['name' => 'A', 'status' => 'ACTIVE'], ['name' => 'A', 'school_id' => 999]] as $data) {
            $this->post('/examinations/grading-schemes', $data)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('grading_schemes', 1);
    }

    public function test_viewer_creator_only_and_unprivileged_permissions(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $viewer = $this->user($u, ['exam.view', 'exam.create']);
        $this->actingAs($viewer)->get('/examinations/grading-schemes')->assertOk()->assertDontSee('Add grading scheme');
        $this->get($this->url($s))->assertOk();
        $this->get('/examinations/grading-schemes/create')->assertForbidden();
        $this->post($this->url($s).'/activate', ['version' => 1])->assertForbidden();
        $none = $this->user($u, []);
        $this->actingAs($none)->get('/examinations/grading-schemes')->assertForbidden();
    }

    public function test_school_isolation_and_guests(): void
    {
        $u = $this->admin();
        $s = $this->ready($u);
        $this->get('/examinations/grading-schemes')->assertRedirect('/login');
        $other = $this->admin('OTHER');
        $this->actingAs($other)->get($this->url($s))->assertNotFound();
        $this->get('/examinations/grading-schemes')->assertOk()->assertViewHas('schemes', fn ($r) => $r->total() === 0);
        try {
            app(GradeResolver::class)->resolve($s, 50);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            app(GradingSchemeService::class)->save($other, $s, ['name' => 'Stolen', 'version' => 4]);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_lookup_rejects_inactive_or_invalid_percentages(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->rule($u, $s);
        $this->actingAs($u)->get($this->url($s).'?percentage=50')->assertSessionHasErrors('percentage');
        app(GradingSchemeService::class)->setStatus($u, $s, GradingSchemeStatus::Active, ['version' => 2]);
        foreach (['-1', '100.01', '1.001', 'hello'] as $p) {
            $this->get($this->url($s).'?percentage='.$p)->assertSessionHasErrors('percentage');
        }
    }

    public function test_scheme_and_rule_pagination_search_and_status_filters(): void
    {
        $u = $this->admin();
        for ($i = 0; $i < 23; $i++) {
            $this->scheme($u, 'Scheme '.$i);
        }
        $this->actingAs($u)->get('/examinations/grading-schemes')->assertOk()->assertViewHas('schemes', fn ($r) => $r->total() === 23 && $r->count() === 20);
        $this->get('/examinations/grading-schemes?page=2')->assertOk()->assertViewHas('schemes', fn ($r) => $r->count() === 3);
        $this->get('/examinations/grading-schemes?q=NONEXISTENT')->assertOk()->assertViewHas('schemes', fn ($r) => $r->total() === 0);
        $this->get('/examinations/grading-schemes?status=ACTIVE')->assertOk()->assertViewHas('schemes', fn ($r) => $r->total() === 0);
        $s = GradingScheme::first();
        for ($i = 0; $i < 23; $i++) {
            $this->rule($u, $s, ['min_percentage' => $i, 'max_percentage' => $i, 'grade' => 'G'.$i]);
        }
        $this->get($this->url($s))->assertOk()->assertViewHas('rules', fn ($r) => $r->total() === 23 && $r->count() === 20);
    }

    public function test_audit_failure_rolls_back_rule_and_scheme_version(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            $this->rule($u, $s);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('grade_rules', 0);
        $this->assertSame(1, $s->fresh()->lock_version);
    }

    public function test_database_constraints_prevent_invalid_ranges_and_cross_school_rules(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $r = $this->rule($u, $s);
        foreach ([['min_percentage' => -1], ['max_percentage' => 101], ['min_percentage' => 90, 'max_percentage' => 80], ['grade_point' => -1], ['school_id' => 999999]] as $bad) {
            try {
                DB::table('grade_rules')->where('id', $r->id)->update($bad);
                $this->fail();
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[0], ['23000', 'HY000']);
            }
        }
    }

    public function test_fresh_actor_permissions_and_audit_version(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        app(GradingSchemeService::class)->save($u, $s, ['name' => 'Updated', 'version' => 1]);
        $audit = DB::table('audit_logs')->where('action', 'GRADING_SCHEME_UPDATED')->first();
        $this->assertSame(2, json_decode($audit->new_values, true)['lock_version']);
        $fresh = $u->fresh();
        $fresh->is_active = false;
        $fresh->save();
        try {
            app(GradingSchemeService::class)->save($u, $s, ['name' => 'Bad', 'version' => 2]);
            $this->fail();
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_single_percentage_rule_and_nullable_zero_grade_points(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->rule($u, $s, ['min_percentage' => 0, 'max_percentage' => 0, 'grade' => 'Zero', 'grade_point' => 0]);
        $this->rule($u, $s, ['min_percentage' => '0.01', 'max_percentage' => 100, 'grade' => 'Any positive']);
        app(GradingSchemeService::class)->setStatus($u, $s, GradingSchemeStatus::Active, ['version' => 3]);
        $this->assertSame('0.00', app(GradeResolver::class)->resolve($s, 0)->grade_point);
        $this->assertSame('Any positive', app(GradeResolver::class)->resolve($s, '0.01')->grade);
        $this->assertNull(app(GradeResolver::class)->resolve($s, 100)->grade_point);
    }

    public function test_lookup_refreshes_status_and_refuses_ambiguous_or_missing_rules(): void
    {
        $u = $this->admin();
        $s = $this->ready($u);
        $r = $s->rules()->orderBy('min_percentage')->first();
        DB::table('grade_rules')->where('id', $r->id)->update(['max_percentage' => 60]);
        try {
            app(GradeResolver::class)->resolve($s, 55);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('percentage', $e->errors());
        }
        DB::table('grade_rules')->where('id', $r->id)->update(['max_percentage' => 40]);
        try {
            app(GradeResolver::class)->resolve($s, 45);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('percentage', $e->errors());
        }
        app(GradingSchemeService::class)->setStatus($u, $s, GradingSchemeStatus::Inactive, ['version' => 4]);
        try {
            app(GradeResolver::class)->resolve($s, 100);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('percentage', $e->errors());
        }
    }

    public function test_activation_audit_failure_keeps_scheme_inactive(): void
    {
        $u = $this->admin();
        $s = $this->scheme($u);
        $this->rule($u, $s);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(GradingSchemeService::class)->setStatus($u, $s, GradingSchemeStatus::Active, ['version' => 2]);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }$this->assertDatabaseHas('grading_schemes', ['id' => $s->id, 'status' => 'INACTIVE', 'lock_version' => 2]);
    }
}

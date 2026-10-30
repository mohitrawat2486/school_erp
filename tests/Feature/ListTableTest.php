<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AcademicYearService;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use App\Support\TablePagination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListTableTest extends TestCase
{
    use RefreshDatabase;

    private function context(): User
    {
        $admin = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('TABLES', 'List Tables School', $admin->email);
        $admin = $admin->fresh();
        app(CurrentSchool::class)->set($admin->school);

        return $admin;
    }

    public function test_assignments_search_across_pages_by_teacher_class_year_and_scope_with_active_filters(): void
    {
        $admin = $this->context();
        $year = app(AcademicYearService::class)->create($admin, ['name' => '2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        $class = SchoolClass::factory()->create(['name' => 'Unique Class']);
        $section = Section::factory()->create(['class_id' => $class->id, 'name' => 'Blue']);
        $teacher = TeacherProfile::factory()->create();
        $teacher->employee->update(['first_name' => 'Ananya', 'middle_name' => null, 'last_name' => 'Rao']);
        TeacherAssignment::factory()->count(25)->create(['academic_year_id' => $year->id]);
        $target = TeacherAssignment::factory()->create(['academic_year_id' => $year->id, 'class_id' => $class->id, 'section_id' => $section->id, 'teacher_id' => $teacher->id]);
        foreach (['Ananya Rao', 'Unique Class', 'Blue'] as $term) {
            $this->actingAs($admin)->get('/academics/teacher-assignments?'.http_build_query(['tables' => ['page' => ['search' => $term, 'length' => 10]]]))
                ->assertOk()->assertSee('data-list-table="page"', false)
                ->assertViewHas('assignments', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id && $records->perPage() === 10);
        }
        $this->get('/academics/teacher-assignments?'.http_build_query(['status' => 'INACTIVE', 'tables' => ['page' => ['search' => 'Ananya']]]))
            ->assertOk()->assertViewHas('assignments', fn ($records) => $records->total() === 0);
        $this->get('/academics/teacher-assignments?'.http_build_query(['page' => 2, 'tables' => ['page' => ['length' => 10]]]))
            ->assertOk()->assertViewHas('assignments', fn ($records) => $records->total() === 26 && $records->count() === 10 && $records->currentPage() === 2);
    }

    public function test_search_does_not_escape_school_scope_and_no_match_does_not_drop_filters(): void
    {
        $admin = $this->context();
        SchoolClass::factory()->create(['name' => 'My Class', 'status' => 'ACTIVE']);
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        SchoolClass::factory()->create(['name' => 'Foreign Class']);
        app(CurrentSchool::class)->set($admin->school);
        $this->actingAs($admin)->get('/academics/classes?'.http_build_query(['tables' => ['page' => ['search' => 'Foreign']]]))->assertOk()->assertViewHas('classes', fn ($rows) => $rows->total() === 0)->assertDontSee('Foreign Class');
        $this->get('/academics/classes?'.http_build_query(['status' => 'INACTIVE', 'tables' => ['page' => ['search' => 'My Class']]]))->assertOk()->assertViewHas('classes', fn ($rows) => $rows->total() === 0);
    }

    public function test_invalid_lengths_and_search_payloads_are_rejected_and_fallback_pagination_remains(): void
    {
        $admin = $this->context();
        SchoolClass::factory()->count(22)->create();
        $this->actingAs($admin)->get('/academics/classes')->assertOk()->assertSee('aria-label="Page 2"', false)->assertViewHas('classes', fn ($rows) => $rows->perPage() === 20);
        foreach ([['length' => -1], ['length' => 10000], ['length' => 11], ['search' => str_repeat('x', 101)], ['search' => ['bad']], ['column' => 'school_id']] as $state) {
            $this->getJson('/academics/classes?'.http_build_query(['tables' => ['page' => $state]]))->assertUnprocessable();
        }
        $this->getJson('/academics/classes?tables=bad')->assertUnprocessable();
        $this->get('/academics/classes?'.http_build_query(['tables' => ['page' => ['search' => "' OR 1=1 --"]]]))->assertOk()->assertViewHas('classes', fn ($rows) => $rows->total() === 0);
    }

    public function test_all_configured_searches_have_valid_columns_and_relationships(): void
    {
        $this->context();
        request()->query->set('tables', ['page' => ['search' => 'No such record']]);
        $models = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $name = 'App\\Models\\'.basename($file, '.php');
            if (is_subclass_of($name, Model::class)) {
                $models[(new $name)->getTable()] = $name;
            }
        }
        foreach (config('list-tables') as $table => $fields) {
            $this->assertArrayHasKey($table, $models);
            $model = $models[$table];
            $records = $model::query()->paginateTable();
            $this->assertSame('No such record', app(TablePagination::class)->metadata($records)['search'], $table);
        }
    }

    public function test_related_tables_use_independent_search_and_page_size_state(): void
    {
        $admin = $this->context();
        request()->query->set('tables', ['enrollment_page' => ['search' => 'Ananya', 'length' => 10], 'page' => ['search' => 'Blue', 'length' => 25]]);
        $first = SchoolClass::query()->paginateTable(10, ['*'], 'enrollment_page');
        $second = SchoolClass::query()->paginateTable();
        $this->assertSame('Ananya', app(TablePagination::class)->metadata($first)['search']);
        $this->assertSame(10, $first->perPage());
        $this->assertSame('Blue', app(TablePagination::class)->metadata($second)['search']);
        $this->assertSame(25, $second->perPage());
    }

    public function test_existing_permissions_apply_to_table_search_requests(): void
    {
        $admin = $this->context();
        $viewer = User::factory()->create(['school_id' => $admin->school_id]);
        $this->actingAs($viewer)->get('/academics/teacher-assignments?'.http_build_query(['tables' => ['page' => ['search' => 'Teacher']]]))->assertForbidden();
    }
}

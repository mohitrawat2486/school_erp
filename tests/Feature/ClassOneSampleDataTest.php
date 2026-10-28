<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\StudentMark;
use App\Models\StudentResult;
use App\Models\User;
use App\Services\ClassOneSampleData;
use App\Services\ProvisionSchool;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClassOneSampleDataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('SAMPLES', 'Sample Test School', $user->email);
        $user = $user->fresh();
        app(CurrentSchool::class)->set($user->school);

        return $user;
    }

    public function test_complete_sample_cohort_is_published_and_repeatable_without_duplicates(): void
    {
        $admin = $this->admin();
        $service = app(ClassOneSampleData::class);
        $summary = $service->run($admin);
        $this->assertFalse($summary['already_exists']);
        foreach (['students' => 20, 'guardians' => 20, 'teacher_profiles' => 10, 'subjects' => 10, 'sections' => 2, 'teacher_assignments' => 22, 'student_enrollments' => 20, 'student_result_subjects' => 200] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame(200, StudentMark::where('status', 'LOCKED')->count());
        $this->assertSame(20, StudentResult::count());
        $this->assertSame(17, StudentResult::where('result_status', 'PASS')->count());
        $this->assertSame(3, StudentResult::where('result_status', 'FAIL')->count());
        $this->assertDatabaseHas('result_batches', ['id' => $summary['batch_id'], 'status' => 'PUBLISHED']);
        $this->assertDatabaseHas('academic_years', ['id' => $summary['academic_year_id'], 'is_current' => true]);
        $this->assertSame(['A' => 10, 'B' => 10], StudentResult::get()->groupBy('section_name')->map->count()->all());
        $before = AuditLog::count();
        $this->assertTrue($service->run($admin)['already_exists']);
        $this->assertSame($before, AuditLog::count());
        $this->assertDatabaseCount('students', 20);
    }

    public function test_existing_class_is_preserved_and_partial_setup_is_not_created(): void
    {
        $admin = $this->admin();
        SchoolClass::factory()->create(['name' => 'Class 1']);
        try {
            app(ClassOneSampleData::class)->run($admin);
            $this->fail('Existing class must be preserved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('class', $exception->errors());
        }
        $this->assertDatabaseCount('academic_years', 0);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('classes', 1);
    }
}

<?php

namespace Tests\Concerns;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\EnrollmentService;
use App\Services\ExamService;
use App\Services\ExamSubjectService;
use App\Services\ExamTermService;
use App\Services\ProvisionSchool;
use App\Services\TeacherAssignmentService;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

trait BuildsMarksContext
{
    // HTTP middleware clears school context; restore it for fixture work between requests.
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($school = auth()->user()?->school) {
            app(CurrentSchool::class)->set($school);
        }

        return $response;
    }

    private function context(): array
    {
        $admin = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('MARKS', 'Marks School', $admin->email);
        $admin = $admin->fresh();
        app(CurrentSchool::class)->set($admin->school);
        $year = AcademicYear::create(['name' => '2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']);
        $year->status = 'ACTIVE';
        $year->save();
        $class = SchoolClass::factory()->create();
        $section = Section::factory()->create(['class_id' => $class->id]);
        $term = app(ExamTermService::class)->save($admin, null, ['academic_year_id' => $year->id, 'name' => 'Annual']);
        $exam = app(ExamService::class)->save($admin, null, ['academic_year_id' => $year->id, 'exam_term_id' => $term->id, 'class_id' => $class->id, 'name' => 'Annual Exam', 'start_date' => '2027-03-01', 'end_date' => '2027-03-10']);
        $allocation = ClassSubject::factory()->create(['academic_year_id' => $year->id, 'class_id' => $class->id]);
        $subject = app(ExamSubjectService::class)->save($admin, $exam, null, ['class_subject_id' => $allocation->id, 'theory_max_marks' => '70', 'practical_max_marks' => '30', 'passing_marks' => '33']);
        app(ExamService::class)->transition($admin, $exam, 'open');
        $exam = $exam->fresh();
        $c = compact('admin', 'year', 'class', 'section', 'term', 'exam', 'allocation', 'subject');
        $c['enrollment'] = $this->enroll($c, 1);

        return $c;
    }

    private function enroll(array $c, int $roll, ?Section $section = null): StudentEnrollment
    {
        $student = Student::factory()->create(['admission_date' => '2026-04-01']);

        return app(EnrollmentService::class)->save($c['admin'], null, ['student_id' => $student->id, 'academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id, 'section_id' => ($section ?? $c['section'])->id, 'roll_number' => $roll, 'joined_at' => '2026-04-01']);
    }

    private function payload(array $c, array $overrides = []): array
    {
        return ['section_id' => $c['section']->id, 'action' => 'draft', 'rows' => [array_replace(['enrollment_id' => $c['enrollment']->id, 'version' => 0, 'theory_marks' => '60', 'practical_marks' => '25', 'is_absent' => false, 'remarks' => 'Good'], $overrides)]];
    }

    private function url(array $c): string
    {
        return '/examinations/marks/'.$c['subject']->id;
    }

    private function user(array $c, array $permissions): User
    {
        $user = User::factory()->create(['school_id' => $c['admin']->school_id]);
        $role = Role::create(['name' => fake()->unique()->bothify('MARKS-####')]);
        app(CurrentSchool::class)->set($c['admin']->school);
        app(AccessManager::class)->setPermissions($c['admin'], $role, DB::table('permissions')->whereIn('name', $permissions)->pluck('id')->all());
        app(AccessManager::class)->assignRoles($c['admin'], $user, [$role->id]);

        return $user;
    }

    private function teacher(array $c, ?Section $section = null): User
    {
        $teacher = TeacherProfile::factory()->create();
        $user = $this->user($c, ['marks.view', 'marks.enter', 'marks.update', 'marks.submit']);
        $user->employee_id = $teacher->employee_id;
        $user->save();
        app(TeacherAssignmentService::class)->create($c['admin'], ['academic_year_id' => $c['year']->id, 'teacher_id' => $teacher->id, 'class_id' => $c['class']->id, 'section_id' => ($section ?? $c['section'])->id, 'subject_id' => $c['subject']->subject_id, 'assignment_type' => 'SUBJECT_TEACHER']);

        return $user;
    }
}

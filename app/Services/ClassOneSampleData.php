<?php

namespace App\Services;

use App\Enums\GradingSchemeStatus;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentMark;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassOneSampleData
{
    public function run(User $actor): array
    {
        abort_unless((int) $actor->school_id === app(CurrentSchool::class)->id() && $actor->can('result.publish') && $actor->can('student.create'), 403);

        return DB::transaction(function () use ($actor) {
            School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            if ($existing = AuditLog::where('action', 'CLASS_ONE_SAMPLE_CREATED')->first()) {
                return [...$existing->new_values, 'already_exists' => true];
            }
            if (SchoolClass::where('name', 'Class 1')->orWhere('code', 'C1')->exists()) {
                throw ValidationException::withMessages(['class' => 'Class 1 already exists. Sample creation does not alter an existing class or its results.']);
            }
            $year = AcademicYear::where('is_current', true)->where('status', 'ACTIVE')->first();
            if (! $year) {
                $startYear = (int) now()->year - (now()->month < 4 ? 1 : 0);
                $year = app(AcademicYearService::class)->create($actor, ['name' => $startYear.'-'.substr((string) ($startYear + 1), -2), 'start_date' => $startYear.'-04-01', 'end_date' => ($startYear + 1).'-03-31']);
                app(AcademicYearService::class)->transition($actor, $year, 'activate');
                $year = $year->fresh();
            }
            $joined = $year->start_date->toDateString();
            $class = app(SchoolClassService::class)->save($actor, null, ['name' => 'Class 1', 'code' => 'C1', 'sort_order' => 1]);
            $sections = [];
            foreach (['A', 'B'] as $name) {
                $sections[] = app(SectionService::class)->save($actor, null, ['class_id' => $class->id, 'name' => $name, 'capacity' => 30]);
            }
            $names = ['English', 'Hindi', 'Mathematics', 'Environmental Studies', 'Computer Basics', 'General Knowledge', 'Art and Craft', 'Physical Education', 'Moral Science', 'Music'];
            $staff = [['Anita', 'Sharma'], ['Priya', 'Verma'], ['Neha', 'Joshi'], ['Rohit', 'Singh'], ['Kavita', 'Rawat'], ['Amit', 'Gupta'], ['Sunita', 'Negi'], ['Vikram', 'Rana'], ['Pooja', 'Nautiyal'], ['Deepak', 'Bisht']];
            $allocations = [];
            foreach ($names as $i => $name) {
                $subject = app(SubjectService::class)->save($actor, null, ['name' => $name, 'code' => 'S'.($i + 1), 'type' => 'CORE']);
                $allocations[] = app(ClassSubjectService::class)->save($actor, null, ['academic_year_id' => $year->id, 'class_id' => $class->id, 'subject_id' => $subject->id, 'theory_max_marks' => $i >= 6 ? null : 100, 'practical_max_marks' => $i >= 6 ? 100 : null, 'passing_marks' => 33]);
                $employee = app(EmployeeService::class)->save($actor, null, ['employee_number' => 'T'.sprintf('%02d', $i + 1), 'first_name' => $staff[$i][0], 'last_name' => $staff[$i][1], 'employee_type' => 'TEACHER', 'email' => 'teacher'.($i + 1).'@example.test', 'joining_date' => $joined, 'designation' => 'Primary Teacher', 'department' => 'Primary', 'address' => 'Fictitious staff record']);
                $teacher = app(TeacherProfileService::class)->save($actor, null, ['employee_id' => $employee->id, 'qualification' => 'B.Ed.', 'specialization' => $name, 'experience' => 3 + $i]);
                foreach ($sections as $section) {
                    app(TeacherAssignmentService::class)->create($actor, ['academic_year_id' => $year->id, 'class_id' => $class->id, 'section_id' => $section->id, 'teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'assignment_type' => 'SUBJECT_TEACHER']);
                }
                if ($i < 2) {
                    app(TeacherAssignmentService::class)->create($actor, ['academic_year_id' => $year->id, 'class_id' => $class->id, 'section_id' => $sections[$i]->id, 'teacher_id' => $teacher->id, 'assignment_type' => 'CLASS_TEACHER']);
                }
            }
            $children = [['Aarav', 'Sharma'], ['Aanya', 'Verma'], ['Vihaan', 'Rawat'], ['Diya', 'Negi'], ['Arjun', 'Singh'], ['Ishita', 'Joshi'], ['Reyansh', 'Gupta'], ['Saanvi', 'Bisht'], ['Kabir', 'Rana'], ['Myra', 'Nautiyal'], ['Aditya', 'Mehta'], ['Anaya', 'Shah'], ['Krish', 'Chauhan'], ['Riya', 'Patel'], ['Dhruv', 'Saxena'], ['Avni', 'Thakur'], ['Vivaan', 'Pandey'], ['Kiara', 'Saini'], ['Atharv', 'Kapoor'], ['Tara', 'Malhotra']];
            $enrollments = [];
            foreach ($sections as $sectionIndex => $section) {
                for ($roll = 1; $roll <= 10; $roll++) {
                    $n = $sectionIndex * 10 + $roll - 1;
                    $student = app(StudentService::class)->save($actor, null, ['admission_number' => '1'.$section->name.'-'.sprintf('%03d', $roll), 'first_name' => $children[$n][0], 'last_name' => $children[$n][1], 'gender' => $roll % 2 ? 'MALE' : 'FEMALE', 'date_of_birth' => $year->start_date->copy()->subYears(6)->addDays($n * 10)->toDateString(), 'admission_date' => $joined, 'address' => 'Fictitious student record']);
                    $guardian = app(GuardianService::class)->save($actor, null, ['first_name' => 'Parent', 'phone' => '+1202555'.sprintf('%04d', 100 + $n), 'last_name' => $children[$n][1], 'email' => 'parent'.($n + 1).'@example.test', 'address' => 'Fictitious guardian record']);
                    app(StudentGuardianService::class)->save($actor, $student, $guardian, ['relationship' => 'FATHER', 'is_primary' => true, 'is_emergency_contact' => true]);
                    $enrollments[$section->id][] = app(EnrollmentService::class)->save($actor, null, ['student_id' => $student->id, 'academic_year_id' => $year->id, 'class_id' => $class->id, 'section_id' => $section->id, 'roll_number' => $roll, 'joined_at' => $joined]);
                }
            }
            $term = app(ExamTermService::class)->save($actor, null, ['academic_year_id' => $year->id, 'name' => 'Term 1']);
            $exam = app(ExamService::class)->save($actor, null, ['academic_year_id' => $year->id, 'exam_term_id' => $term->id, 'class_id' => $class->id, 'name' => 'Assessment - Class 1', 'start_date' => $year->start_date->copy()->addMonths(5)->toDateString(), 'end_date' => $year->start_date->copy()->addMonths(5)->addDays(9)->toDateString()]);
            $examSubjects = [];
            foreach ($allocations as $allocation) {
                $examSubjects[] = app(ExamSubjectService::class)->save($actor, $exam, null, ['class_subject_id' => $allocation->id, ...$allocation->only(['theory_max_marks', 'practical_max_marks', 'passing_marks'])]);
            }
            app(ExamService::class)->transition($actor, $exam, 'open');
            foreach ($examSubjects as $subjectIndex => $subject) {
                foreach ($sections as $sectionIndex => $section) {
                    $rows = [];
                    foreach ($enrollments[$section->id] as $enrollment) {
                        $roll = $enrollment->roll_number;
                        $absent = $sectionIndex === 1 && $roll === 9 && $subjectIndex === 4;
                        $score = $roll === 10 && $subjectIndex === 0 ? 20 : 45 + (($roll * 7 + $subjectIndex * 9 + $sectionIndex * 11) % 51);
                        $rows[] = ['enrollment_id' => $enrollment->id, 'version' => 0, 'theory_marks' => $absent || $subject->theory_max_marks === null ? null : $score, 'practical_marks' => $absent || $subject->practical_max_marks === null ? null : $score, 'is_absent' => $absent, 'remarks' => $absent ? 'Absent in assessment.' : ($score < 33 ? 'Needs additional practice.' : 'Steady progress.')];
                    }
                    app(MarksEntryService::class)->save($actor, $subject, $section, ['action' => 'submit', 'section_id' => $section->id, 'rows' => $rows]);
                }
            }
            foreach (StudentMark::where('exam_id', $exam->id)->get() as $mark) {
                app(MarksVerificationService::class)->transition($actor, $mark, ['action' => 'verify', 'version' => $mark->lock_version]);
                app(MarksVerificationService::class)->transition($actor, $mark->fresh(), ['action' => 'lock', 'version' => $mark->fresh()->lock_version]);
            }
            $grading = app(GradingSchemeService::class);
            $scheme = $grading->save($actor, null, ['name' => 'Primary Grades']);
            foreach ([[0, 32.99, 'F', 0], [33, 49.99, 'D', 4], [50, 64.99, 'C', 5], [65, 79.99, 'B', 6], [80, 89.99, 'A', 8], [90, 100, 'A+', 10]] as [$min,$max,$grade,$point]) {
                $grading->saveRule($actor, $scheme, null, ['version' => $scheme->fresh()->lock_version, 'min_percentage' => $min, 'max_percentage' => $max, 'grade' => $grade, 'grade_point' => $point]);
            }
            $grading->setStatus($actor, $scheme, GradingSchemeStatus::Active, ['version' => $scheme->fresh()->lock_version]);
            $approval = app(ResultApprovalService::class);
            $batch = $approval->generate($actor, $exam->fresh(), $scheme->fresh());
            $approval->transition($actor, $batch, ['action' => 'approve', 'version' => 1]);
            $approval->transition($actor, $batch, ['action' => 'publish', 'version' => 2]);
            $summary = ['academic_year_id' => $year->id, 'academic_year' => $year->name, 'class_id' => $class->id, 'exam_id' => $exam->id, 'batch_id' => $batch->id, 'sections' => ['1A', '1B'], 'students' => 20, 'guardians' => 20, 'teachers' => 10, 'subjects' => 10, 'teacher_assignments' => 22, 'marks' => 200, 'published_results' => 20];
            app(AuditLogger::class)->record($actor, 'CLASS_ONE_SAMPLE_CREATED', 'result_batch', $batch->id, [], $summary, 'samples');

            return [...$summary, 'already_exists' => false];
        });
    }
}

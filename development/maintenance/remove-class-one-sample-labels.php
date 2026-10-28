<?php

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Exam;
use App\Models\ExamTerm;
use App\Models\GradingScheme;
use App\Models\Guardian;
use App\Models\ResultBatch;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CurrentSchool;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Run from the project root: php development/maintenance/remove-class-one-sample-labels.php OPERATOR_ID
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$actor = User::findOrFail($argv[1] ?? null);
app(CurrentSchool::class)->set($actor->school);
try {
    abort_unless($actor->can('rbac.manage') && $actor->can('result.publish'), 403);
    $counts = DB::transaction(function () use ($actor) {
        School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
        $marker = AuditLog::where('action', 'CLASS_ONE_SAMPLE_CREATED')->firstOrFail();
        $ids = $marker->new_values;
        $exam = Exam::findOrFail($ids['exam_id']);
        $batch = ResultBatch::findOrFail($ids['batch_id']);
        $students = Student::whereHas('enrollments', fn ($q) => $q->where('academic_year_id', $ids['academic_year_id'])->where('class_id', $ids['class_id']))->where('admission_number', 'like', 'SAMPLE-%')->get();
        $guardianIds = StudentGuardian::whereIn('student_id', $students->modelKeys())->pluck('guardian_id');
        $allocations = ClassSubject::where('academic_year_id', $ids['academic_year_id'])->where('class_id', $ids['class_id'])->get();
        $teacherIds = TeacherAssignment::where('academic_year_id', $ids['academic_year_id'])->where('class_id', $ids['class_id'])->pluck('teacher_id');
        $employeeIds = TeacherProfile::whereIn('id', $teacherIds)->pluck('employee_id');
        $groups = [
            [collect([AcademicYear::findOrFail($ids['academic_year_id'])]), ['name']],
            [collect([SchoolClass::findOrFail($ids['class_id'])]), ['code']],
            [collect([$exam]), ['name']],
            [collect([ExamTerm::findOrFail($exam->exam_term_id)]), ['name']],
            [collect([GradingScheme::findOrFail($batch->grading_scheme_id)]), ['name']],
            [Subject::whereIn('id', $allocations->pluck('subject_id'))->get(), ['code']],
            [$students, ['admission_number', 'address']],
            [Guardian::whereIn('id', $guardianIds)->get(), ['first_name', 'email', 'address']],
            [Employee::whereIn('id', $employeeIds)->get(), ['employee_number', 'designation', 'email', 'address']],
        ];
        $counts = [];
        foreach ($groups as [$models, $fields]) {
            foreach ($models as $model) {
                $old = $model->only($fields);
                foreach ($fields as $field) {
                    if (! is_string($model->$field)) {
                        continue;
                    }
                    $value = str_ireplace(['(Sample)', 'SAMPLE-', 'sample.'], '', $model->$field);
                    $value = trim(preg_replace('/\bsample\b\s*/i', '', $value));
                    if ($model instanceof ExamTerm && $value === 'Term') {
                        $value = 'Term 1';
                    }
                    $model->$field = $value;
                }
                if (! $model->isDirty()) {
                    continue;
                }
                // Metadata-only maintenance; no statuses, grades, marks, identity IDs or snapshots change.
                $model->save();
                app(AuditLogger::class)->record($actor, 'DATA_LABELS_CLEANED', $model->getTable(), $model->id, $old, $model->only($fields), 'maintenance');
                $counts[$model->getTable()] = ($counts[$model->getTable()] ?? 0) + 1;
            }
        }

        return $counts;
    });
    echo json_encode($counts, JSON_PRETTY_PRINT), "\n";
    echo "Updated editable labels. Published snapshots, locked marks and existing audit history retained.\n";
} finally {
    app(CurrentSchool::class)->clear();
}

<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ClassResultController;
use App\Http\Controllers\ClassSubjectController;
use App\Http\Controllers\ClassTeacherPickerController;
use App\Http\Controllers\EmployeeAccountController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\ExamSubjectController;
use App\Http\Controllers\ExamTermController;
use App\Http\Controllers\GradingSchemeController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\ManagedUserController;
use App\Http\Controllers\MarksEntryController;
use App\Http\Controllers\MarksVerificationController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ReportCardController;
use App\Http\Controllers\ReportPdfController;
use App\Http\Controllers\ResultApprovalController;
use App\Http\Controllers\RoleAdministrationController;
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\SchoolDetailsController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentEnrollmentController;
use App\Http\Controllers\StudentGuardianController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectClassesController;
use App\Http\Controllers\TeacherAssignmentController;
use App\Http\Controllers\TeacherProfileController;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\Route;

Route::pattern('managed_user', '[0-9]+');
Route::pattern('role', '[0-9]+');
Route::bind('managed_user', fn ($id) => User::where('school_id', app(CurrentSchool::class)->id())->whereKey($id)->firstOrFail());

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});
Route::middleware('auth')->group(function () {
    Route::middleware('school')->group(function () {
        Route::get('/settings/school', [SchoolDetailsController::class, 'show'])->name('school.show');
        Route::put('/settings/school', [SchoolDetailsController::class, 'update'])->middleware('can:school.view')->name('school.update');
        Route::get('/school/logo', [SchoolDetailsController::class, 'logo'])->name('school.logo');
        Route::prefix('academics')->middleware('can:academic_year.view')->group(function () {
            Route::post('academic-years/{academic_year}/activate', [AcademicYearController::class, 'activate'])->name('academic-years.activate');
            Route::post('academic-years/{academic_year}/close', [AcademicYearController::class, 'close'])->name('academic-years.close');
            Route::post('academic-years/{academic_year}/archive', [AcademicYearController::class, 'archive'])->name('academic-years.archive');
            Route::resource('academic-years', AcademicYearController::class)->parameters(['academic-years' => 'academic_year']);
        });
        Route::prefix('administration')->name('admin.')->middleware('can:rbac.view')->group(function () {
            Route::get('users', [ManagedUserController::class, 'index'])->name('users.index');
            Route::get('users/create', [ManagedUserController::class, 'create'])->name('users.create');
            Route::post('users', [ManagedUserController::class, 'store'])->name('users.store');
            Route::get('users/{managed_user}/edit', [ManagedUserController::class, 'edit'])->name('users.edit');
            Route::put('users/{managed_user}', [ManagedUserController::class, 'update'])->name('users.update');
            Route::get('roles', [RoleAdministrationController::class, 'index'])->name('roles.index');
            Route::get('roles/create', [RoleAdministrationController::class, 'create'])->name('roles.create');
            Route::post('roles', [RoleAdministrationController::class, 'store'])->name('roles.store');
            Route::get('roles/{role}/edit', [RoleAdministrationController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}', [RoleAdministrationController::class, 'update'])->name('roles.update');
            Route::get('permissions', [RoleAdministrationController::class, 'permissions'])->name('permissions.index');
        });
        Route::middleware('can:result.view')->group(function () {
            Route::get('/report-cards/{student_result}/pdf', [ReportPdfController::class, 'individual'])->name('report-pdfs.individual');
            Route::get('/report-pdfs', [ReportPdfController::class, 'index'])->name('report-pdfs.index');
            Route::post('/report-pdfs', [ReportPdfController::class, 'store'])->name('report-pdfs.store');
            Route::get('/report-pdfs/{report_pdf_request}', [ReportPdfController::class, 'show'])->name('report-pdfs.show');
            Route::get('/report-pdfs/{report_pdf_request}/download', [ReportPdfController::class, 'download'])->name('report-pdfs.download');
        });
        Route::get('/report-cards/{student_result}', [ReportCardController::class, 'show'])->middleware('can:result.view')->name('report-cards.show');
        Route::prefix('class-results')->middleware('can:result.view')->group(function () {
            Route::get('/', [ClassResultController::class, 'index'])->name('class-results.index');
            Route::get('/select', [ClassResultController::class, 'select'])->name('class-results.select');
            Route::match(['get', 'post'], '/print', [ClassResultController::class, 'print'])->name('class-results.print');
            Route::get('/export', [ClassResultController::class, 'export'])->name('class-results.export');
        });
        Route::get('/published-results/{student_result}', [ResultApprovalController::class, 'snapshot'])->middleware('can:result.view')->name('results.snapshot');
        Route::prefix('results')->middleware('can:result.view')->group(function () {
            Route::get('/', [ResultApprovalController::class, 'index'])->name('results.index');
            Route::get('/create', [ResultApprovalController::class, 'create'])->name('results.create');
            Route::post('/', [ResultApprovalController::class, 'store'])->name('results.store');
            Route::get('/{result_batch}', [ResultApprovalController::class, 'show'])->name('results.show');
            Route::get('/{result_batch}/students/{result_draft}', [ResultApprovalController::class, 'student'])->name('results.student');
            Route::post('/{result_batch}', [ResultApprovalController::class, 'transition'])->name('results.transition');
        });
        Route::prefix('examinations/grading-schemes')->middleware('can:exam.view')->group(function () {
            Route::get('/', [GradingSchemeController::class, 'index'])->name('grading.index');
            Route::get('/create', [GradingSchemeController::class, 'create'])->name('grading.create');
            Route::post('/', [GradingSchemeController::class, 'store'])->name('grading.store');
            Route::get('/{grading_scheme}', [GradingSchemeController::class, 'show'])->name('grading.show');
            Route::get('/{grading_scheme}/edit', [GradingSchemeController::class, 'edit'])->name('grading.edit');
            Route::put('/{grading_scheme}', [GradingSchemeController::class, 'update'])->name('grading.update');
            foreach (['activate' => 'ACTIVE', 'deactivate' => 'INACTIVE'] as $action => $target) {
                Route::post('/{grading_scheme}/'.$action, [GradingSchemeController::class, 'status'])->defaults('target', $target)->name('grading.'.$action);
            }
            Route::get('/{grading_scheme}/rules/create', [GradingSchemeController::class, 'ruleCreate'])->name('grading.rules.create');
            Route::post('/{grading_scheme}/rules', [GradingSchemeController::class, 'ruleStore'])->name('grading.rules.store');
            Route::get('/{grading_scheme}/rules/{grade_rule}/edit', [GradingSchemeController::class, 'ruleEdit'])->name('grading.rules.edit');
            Route::put('/{grading_scheme}/rules/{grade_rule}', [GradingSchemeController::class, 'ruleUpdate'])->name('grading.rules.update');
            Route::delete('/{grading_scheme}/rules/{grade_rule}', [GradingSchemeController::class, 'ruleDelete'])->name('grading.rules.destroy');
        });
        Route::prefix('examinations/marks-review')->middleware('can:marks.view')->group(function () {
            Route::get('/', [MarksVerificationController::class, 'index'])->name('marks-review.index');
            Route::get('/{student_mark}', [MarksVerificationController::class, 'show'])->name('marks-review.show');
            Route::post('/{student_mark}', [MarksVerificationController::class, 'transition'])->name('marks-review.transition');
        });
        Route::prefix('examinations/marks')->middleware('can:marks.view')->group(function () {
            Route::get('/', [MarksEntryController::class, 'index'])->name('marks.index');
            Route::get('/select', [MarksEntryController::class, 'select'])->name('marks.select');
            Route::get('/{exam_subject}', [MarksEntryController::class, 'show'])->name('marks.show');
            Route::post('/{exam_subject}', [MarksEntryController::class, 'store'])->name('marks.store');
        });
        Route::prefix('examinations')->middleware('can:exam.view')->group(function () {
            Route::get('exam-subjects', [ExamSubjectController::class, 'index'])->name('exam-subjects.index');
            Route::get('exams/{exam}/subjects/create', [ExamSubjectController::class, 'create'])->name('exam-subjects.create');
            Route::post('exams/{exam}/subjects', [ExamSubjectController::class, 'store'])->name('exam-subjects.store');
            Route::get('exam-subjects/{exam_subject}', [ExamSubjectController::class, 'show'])->name('exam-subjects.show');
            Route::get('exam-subjects/{exam_subject}/edit', [ExamSubjectController::class, 'edit'])->name('exam-subjects.edit');
            Route::put('exam-subjects/{exam_subject}', [ExamSubjectController::class, 'update'])->name('exam-subjects.update');
            Route::post('exam-subjects/{exam_subject}/activate', [ExamSubjectController::class, 'activate'])->name('exam-subjects.activate');
            Route::post('exam-subjects/{exam_subject}/deactivate', [ExamSubjectController::class, 'deactivate'])->name('exam-subjects.deactivate');

            Route::post('terms/{exam_term}/activate', [ExamTermController::class, 'activate'])->name('exam-terms.activate');
            Route::post('terms/{exam_term}/deactivate', [ExamTermController::class, 'deactivate'])->name('exam-terms.deactivate');
            Route::resource('terms', ExamTermController::class)->parameters(['terms' => 'exam_term'])->names('exam-terms')->except('destroy');
            foreach (['open', 'draft', 'close'] as $action) {
                Route::post('exams/{exam}/'.$action, [ExamController::class, 'transition'])->defaults('action', $action)->name('exams.'.$action);
            }
            Route::resource('exams', ExamController::class)->except('destroy');
        });
        Route::middleware('can:student.view')->group(function () {
            Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
            Route::get('promotions/create', [PromotionController::class, 'create'])->name('promotions.create');
            Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
            Route::get('promotions/{promotion}', [PromotionController::class, 'show'])->name('promotions.show');
        });
        Route::middleware('can:student.view')->group(function () {
            Route::post('enrollments/{enrollment}/activate', [StudentEnrollmentController::class, 'activate'])->name('enrollments.activate');
            Route::post('enrollments/{enrollment}/deactivate', [StudentEnrollmentController::class, 'deactivate'])->name('enrollments.deactivate');
            Route::resource('enrollments', StudentEnrollmentController::class)->except('destroy');
        });
        Route::middleware('can:student.view')->group(function () {
            Route::post('guardians/{guardian}/activate', [GuardianController::class, 'activate'])->name('guardians.activate');
            Route::post('guardians/{guardian}/deactivate', [GuardianController::class, 'deactivate'])->name('guardians.deactivate');
            Route::resource('guardians', GuardianController::class)->except('destroy');
            Route::get('students/{student}/guardians', [StudentGuardianController::class, 'index'])->name('students.guardians.index');
            Route::put('students/{student}/guardians/{guardian}', [StudentGuardianController::class, 'update'])->name('students.guardians.update');
            Route::post('students/{student}/guardians/{guardian}/unlink', [StudentGuardianController::class, 'unlink'])->name('students.guardians.unlink');
        });
        Route::middleware('can:student.view')->group(function () {
            Route::post('students/{student}/activate', [StudentController::class, 'activate'])->name('students.activate');
            Route::post('students/{student}/deactivate', [StudentController::class, 'deactivate'])->name('students.deactivate');
            Route::get('students/{student}/photo', [StudentController::class, 'photo'])->name('students.photo');
            Route::resource('students', StudentController::class)->except('destroy');
        });
        Route::middleware('can:employee.view')->group(function () {
            Route::post('employees/{employee}/activate', [EmployeeController::class, 'activate'])->name('employees.activate');
            Route::post('employees/{employee}/deactivate', [EmployeeController::class, 'deactivate'])->name('employees.deactivate');
            Route::get('employees/{employee}/photo', [EmployeeController::class, 'photo'])->name('employees.photo');
            Route::resource('employees', EmployeeController::class)->except('destroy');
        });
        Route::middleware('can:teacher.view')->group(function () {
            Route::post('teachers/{teacher_profile}/activate', [TeacherProfileController::class, 'activate'])->name('teachers.activate');
            Route::post('teachers/{teacher_profile}/deactivate', [TeacherProfileController::class, 'deactivate'])->name('teachers.deactivate');
            Route::resource('teachers', TeacherProfileController::class)->parameters(['teachers' => 'teacher_profile'])->except('destroy');
        });
        Route::prefix('academics')->middleware('can:class.view')->group(function () {
            Route::get('classes/teacher-options', ClassTeacherPickerController::class)->middleware('throttle:120,1')->name('classes.teacher-options');
            Route::post('classes/{school_class}/activate', [SchoolClassController::class, 'activate'])->name('classes.activate');
            Route::post('classes/{school_class}/deactivate', [SchoolClassController::class, 'deactivate'])->name('classes.deactivate');
            Route::resource('classes', SchoolClassController::class)->parameters(['classes' => 'school_class'])->except('destroy');
        });
        Route::prefix('academics')->middleware('can:section.view')->group(function () {
            Route::get('sections/classes', [SectionController::class, 'chooseClass'])->name('sections.choose-class');
            Route::post('sections/{section}/activate', [SectionController::class, 'activate'])->name('sections.activate');
            Route::post('sections/{section}/deactivate', [SectionController::class, 'deactivate'])->name('sections.deactivate');
            Route::resource('sections', SectionController::class)->except('destroy');
        });
        Route::prefix('academics')->middleware('can:subject.view')->group(function () {
            Route::get('subjects/{subject}/classes', [SubjectClassesController::class, 'edit'])->name('subjects.classes.edit');
            Route::put('subjects/{subject}/classes', [SubjectClassesController::class, 'update'])->name('subjects.classes.update');
            Route::post('subjects/{subject}/activate', [SubjectController::class, 'activate'])->name('subjects.activate');
            Route::post('subjects/{subject}/deactivate', [SubjectController::class, 'deactivate'])->name('subjects.deactivate');
            Route::resource('subjects', SubjectController::class)->except('destroy');
            Route::get('class-subjects/filter', [ClassSubjectController::class, 'chooseFilters'])->name('class-subjects.filter');
            Route::post('class-subjects/{class_subject}/activate', [ClassSubjectController::class, 'activate'])->name('class-subjects.activate');
            Route::post('class-subjects/{class_subject}/deactivate', [ClassSubjectController::class, 'deactivate'])->name('class-subjects.deactivate');
            Route::resource('class-subjects', ClassSubjectController::class)->parameters(['class-subjects' => 'class_subject'])->except('destroy');
        });
        Route::prefix('academics')->middleware('can:teacher_assignment.view')->group(function () {
            Route::get('teacher-assignments/filter', [TeacherAssignmentController::class, 'chooseFilters'])->name('assignments.filter');
            Route::get('teacher-assignments', [TeacherAssignmentController::class, 'index'])->name('assignments.index');
            Route::get('teacher-assignments/create', [TeacherAssignmentController::class, 'create'])->name('assignments.create');
            Route::post('teacher-assignments', [TeacherAssignmentController::class, 'store'])->name('assignments.store');
            Route::get('teacher-assignments/{teacher_assignment}', [TeacherAssignmentController::class, 'show'])->name('assignments.show');
            Route::post('teacher-assignments/{teacher_assignment}/activate', [TeacherAssignmentController::class, 'activate'])->name('assignments.activate');
            Route::post('teacher-assignments/{teacher_assignment}/deactivate', [TeacherAssignmentController::class, 'deactivate'])->name('assignments.deactivate');
        });
        Route::get('administration/users/{managed_user}/employee', [EmployeeAccountController::class, 'index'])->middleware('can:rbac.view')->name('admin.users.employee');
        Route::put('administration/users/{managed_user}/employee', [EmployeeAccountController::class, 'update'])->middleware('can:rbac.view')->name('admin.users.employee.update');
        Route::view('/dashboard', 'dashboard')->name('dashboard');
        Route::get('/administration/audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit.index');
        Route::get('/administration/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('can:audit.view')->name('audit.show');
    });
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

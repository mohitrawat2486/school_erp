<?php

// Search only fields shown by the corresponding directories, with historical
// result names read from captured values rather than current student records.
return [
    'academic_years' => ['name', 'start_date', 'end_date', 'status'],
    'classes' => ['name', 'code', 'status', 'sections.name'],
    'sections' => ['name', 'capacity', 'status', 'schoolClass.name'],
    'subjects' => ['name', 'code', 'type', 'status'],
    'employees' => ['@name', 'employee_number', 'employee_type', 'designation', 'department', 'phone', 'status'],
    'teacher_profiles' => ['employee.@name', 'employee.employee_number', 'specialization', 'qualification', 'experience', 'status'],
    'teacher_assignments' => ['academicYear.name', 'teacher.employee.@name', 'teacher.employee.employee_number', 'schoolClass.name', 'section.name', 'assignment_type', 'subject.name', 'status'],
    'students' => ['@name', 'admission_number', 'gender', 'status'],
    'guardians' => ['@guardian', 'phone', 'email', 'status'],
    'student_guardians' => ['guardian.@guardian', 'guardian.phone', 'student.@name', 'student.admission_number', 'relationship', 'status'],
    'student_enrollments' => ['student.@name', 'student.admission_number', 'academicYear.name', 'schoolClass.name', 'section.name', 'roll_number', 'status'],
    'class_subjects' => ['academicYear.name', 'schoolClass.name', 'subject.name', 'subject.code', 'status'],
    'exam_terms' => ['name', 'academicYear.name', 'status'],
    'exams' => ['name', 'academicYear.name', 'schoolClass.name', 'examTerm.name', 'status'],
    'exam_subjects' => ['exam.name', 'exam.academicYear.name', 'exam.schoolClass.name', 'subject.name', 'subject.code', 'status'],
    'student_marks' => ['enrollment.student.@name', 'enrollment.roll_number', 'examSubject.subject.name', 'examSubject.exam.name', 'status'],
    'grading_schemes' => ['name', 'status'],
    'grade_rules' => ['grade', 'min_percentage', 'max_percentage', 'grade_point', 'remarks'],
    'result_batches' => ['calculation_context->exam_name', 'calculation_context->academic_year_name', 'calculation_context->class_name', 'status', 'revision'],
    'result_drafts' => ['payload->student_name', 'payload->section_name', 'payload->roll_number', 'payload->grade', 'payload->result_status'],
    'student_results' => ['student_name', 'section_name', 'roll_number', 'exam_name', 'grade', 'result_status'],
    'student_result_subjects' => ['subject_name', 'grade', 'result_status'],
    'promotion_outcomes' => ['student.@name', 'student.admission_number', 'sourceEnrollment.academicYear.name', 'sourceEnrollment.schoolClass.name', 'sourceEnrollment.section.name', 'targetEnrollment.academicYear.name', 'targetEnrollment.schoolClass.name', 'targetEnrollment.section.name', 'action', 'notes'],
    'promotion_batches' => ['sourceYear.name', 'sourceClass.name', 'sourceSection.name', 'targetYear.name', 'targetClass.name', 'targetSection.name', 'mode'],
    'report_pdf_requests' => ['label', 'status', 'student_count', 'created_at'],
    'audit_logs' => ['action', 'module', 'entity_type', 'entity_id', 'context->actor_name', 'context->student_name', 'context->subject_name', 'user_id', 'created_at'],
    'users' => ['name', 'email', 'roles.name'],
    'roles' => ['name'],
];

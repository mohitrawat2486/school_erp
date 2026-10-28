<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\GradingScheme;
use App\Models\StudentEnrollment;
use App\Models\StudentMark;
use App\Support\CurrentSchool;

class ResultSourceFingerprint
{
    /** Caller holds the school lock; excludes exam status changed by approval workflow. */
    public function hash(Exam $exam, GradingScheme $scheme): string
    {
        $exam->load(['academicYear', 'examTerm', 'schoolClass']);
        $subjects = $exam->examSubjects()->with(['subject', 'classSubject'])->where('status', 'ACTIVE')->orderBy('id')->get();
        $roster = StudentEnrollment::with(['student', 'section'])->where('academic_year_id', $exam->academic_year_id)->where('class_id', $exam->class_id)->where('status', 'ACTIVE')->whereHas('student', fn ($q) => $q->where('status', 'ACTIVE'))->orderBy('id')->get();
        $source = ['report_school' => app(ReportCardContext::class)->capture(app(CurrentSchool::class)->get()), 'exam' => $exam->only(['id', 'academic_year_id', 'class_id', 'exam_term_id', 'name', 'start_date', 'end_date']), 'year' => $exam->academicYear->only(['name', 'status']), 'term' => $exam->examTerm->only(['name', 'status']), 'class' => $exam->schoolClass->only(['name', 'status']), 'scheme' => $scheme->only(['id', 'name', 'status', 'lock_version']), 'rules' => $scheme->rules()->orderBy('id')->get()->map->only(['id', 'min_percentage', 'max_percentage', 'grade', 'grade_point', 'remarks'])->all(), 'subjects' => $subjects->map(fn ($s) => [$s->only(['id', 'subject_id', 'theory_max_marks', 'practical_max_marks', 'passing_marks', 'status']), $s->subject->only(['name', 'code', 'status']), $s->classSubject->only(['id', 'status'])])->all(), 'roster' => $roster->map(fn ($e) => [$e->only(['id', 'student_id', 'section_id', 'roll_number', 'status']), $e->student->only(['first_name', 'middle_name', 'last_name', 'admission_number', 'date_of_birth', 'status']), $e->section->only(['id', 'class_id', 'name', 'status'])])->all(), 'marks' => StudentMark::where('exam_id', $exam->id)->orderBy('id')->get()->map->only(['id', 'enrollment_id', 'student_id', 'exam_subject_id', 'theory_marks', 'practical_marks', 'total_marks', 'is_absent', 'remarks', 'status', 'lock_version', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at', 'locked_by', 'locked_at'])->all()];

        return self::digest($source);
    }

    public static function digest(array $data): string
    {
        return hash('sha256', json_encode(self::canonical($data), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }if (! array_is_list($data)) {
            ksort($data);
        }foreach ($data as &$value) {
            $value = self::canonical($value);
        }

        return $data;
    }
}

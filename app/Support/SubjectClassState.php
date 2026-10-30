<?php

namespace App\Support;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherAssignment;

final class SubjectClassState
{
    public static function revision(Subject $subject, AcademicYear $year): string
    {
        return hash('sha256', json_encode([
            'subject' => $subject->fresh()->only(['id', 'name', 'code', 'type', 'status', 'updated_at']),
            'year' => $year->fresh()->only(['id', 'name', 'status', 'is_current', 'updated_at']),
            'classes' => SchoolClass::orderBy('id')->get(['id', 'name', 'status', 'updated_at'])->toArray(),
            'mappings' => ClassSubject::where('subject_id', $subject->id)->where('academic_year_id', $year->id)->orderBy('id')->get(['id', 'class_id', 'status', 'theory_max_marks', 'practical_max_marks', 'passing_marks', 'updated_at'])->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    public static function isUsed(ClassSubject $allocation): bool
    {
        return $allocation->examSubjects()->exists() || TeacherAssignment::where('academic_year_id', $allocation->academic_year_id)->where('class_id', $allocation->class_id)->where('subject_id', $allocation->subject_id)->exists();
    }
}

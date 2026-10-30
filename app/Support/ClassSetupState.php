<?php

namespace App\Support;

use App\Models\SchoolClass;
use App\Models\TeacherAssignment;

final class ClassSetupState
{
    public static function revision(SchoolClass $class): string
    {
        $class = SchoolClass::findOrFail($class->id);
        $year = app(CurrentSchool::class)->academicYear();

        return hash('sha256', json_encode([
            'class' => $class->only(['id', 'school_id', 'name', 'code', 'status', 'sort_order', 'updated_at']),
            'year' => $year?->only(['id', 'status', 'is_current']),
            'sections' => $class->sections()->orderBy('id')->get(['id', 'name', 'capacity', 'status', 'updated_at'])->toArray(),
            'teachers' => $year ? TeacherAssignment::where('class_id', $class->id)->where('academic_year_id', $year->id)->orderBy('id')->get(['id', 'teacher_id', 'section_id', 'subject_id', 'assignment_type', 'status', 'updated_at'])->toArray() : [],
            'subjects' => $year ? $class->classSubjects()->where('academic_year_id', $year->id)->orderBy('id')->get(['id', 'subject_id', 'status', 'theory_max_marks', 'practical_max_marks', 'passing_marks', 'updated_at'])->toArray() : [],
        ], JSON_THROW_ON_ERROR));
    }
}

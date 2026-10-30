<?php

namespace App\Http\Requests;

use App\Enums\AssignmentType;
use App\Enums\TeacherAssignmentStatus;
use App\Models\TeacherAssignment;
use App\Support\CurrentSchool;
use App\Support\TeacherWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', TeacherAssignment::class);
    }

    public function rules(): array
    {
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'assignment_type' => ['nullable', Rule::enum(AssignmentType::class)], 'status' => ['nullable', Rule::enum(TeacherAssignmentStatus::class)]];
        foreach (['academic_year_id' => 'academic_years', 'teacher_id' => 'teacher_profiles', 'class_id' => 'classes'] as $field => $table) {
            $exists = Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id());
            if ($field === 'academic_year_id') {
                $exists->whereNull('deleted_at');
            } $rules[$field] = ['nullable', 'integer', $exists];
        }

        return $rules + TeacherWorkspace::rules();
    }
}

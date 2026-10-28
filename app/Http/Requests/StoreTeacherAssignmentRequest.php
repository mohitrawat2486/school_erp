<?php

namespace App\Http\Requests;

use App\Enums\AssignmentType;
use App\Models\TeacherAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TeacherAssignment::class);
    }

    public function rules(): array
    {
        return ['assignment_type' => ['required', Rule::enum(AssignmentType::class)], 'academic_year_id' => ['required', 'integer'], 'teacher_id' => ['required', 'integer'], 'class_id' => ['required', 'integer'], 'section_id' => ['nullable', 'integer'], 'subject_id' => ['nullable', 'integer', Rule::requiredIf($this->input('assignment_type') === AssignmentType::SubjectTeacher->value), Rule::prohibitedIf($this->input('assignment_type') === AssignmentType::ClassTeacher->value)], 'class_subject_id' => ['prohibited'], 'school_id' => ['prohibited'], 'status' => ['prohibited'], 'id' => ['prohibited']];
    }
}

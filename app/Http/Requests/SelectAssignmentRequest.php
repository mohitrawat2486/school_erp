<?php

namespace App\Http\Requests;

use App\Enums\AssignmentType;
use App\Models\TeacherAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('assignments.create') ? 'create' : 'viewAny', TeacherAssignment::class);
    }

    public function rules(): array
    {
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'assignment_type' => ['nullable', Rule::enum(AssignmentType::class)], 'scope' => ['nullable', Rule::in(['ALL', 'SECTION'])]];
        foreach (['academic_year_id', 'teacher_id', 'class_id', 'section_id', 'subject_id'] as $field) {
            $rules[$field] = ['nullable', 'integer'];
        }

return $rules;
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\EnrollmentStatus;
use App\Models\StudentEnrollment;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrollmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', StudentEnrollment::class);
    }

    public function rules(): array
    {
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(EnrollmentStatus::class)]];
        foreach (['student_id' => 'students', 'academic_year_id' => 'academic_years', 'class_id' => 'classes', 'section_id' => 'sections'] as $field => $table) {
            $rules[$field] = ['nullable', 'integer', Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id())];
        }

return $rules;
    }
}

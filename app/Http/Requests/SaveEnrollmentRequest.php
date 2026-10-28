<?php

namespace App\Http\Requests;

use App\Models\StudentEnrollment;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($record = $this->route('enrollment')) ? $this->user()->can('update', $record) : $this->user()->can('create', StudentEnrollment::class);
    }

    public function rules(): array
    {
        $rules = ['roll_number' => ['required', 'integer', 'between:1,4294967295'], 'joined_at' => ['required', 'date_format:Y-m-d'], 'school_id' => ['prohibited'], 'id' => ['prohibited'], 'status' => ['prohibited']];
        foreach (['student_id' => 'students', 'academic_year_id' => 'academic_years', 'class_id' => 'classes', 'section_id' => 'sections'] as $field => $table) {
            $rules[$field] = $this->route('enrollment') ? ['prohibited'] : ['required', 'integer', Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id())];
        }

return $rules;
    }
}

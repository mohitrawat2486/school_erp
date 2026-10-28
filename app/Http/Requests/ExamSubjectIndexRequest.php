<?php

namespace App\Http\Requests;

use App\Enums\ExamSubjectStatus;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExamSubjectIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('exam.view');
    }

    public function rules(): array
    {
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(ExamSubjectStatus::class)]];
        foreach (['exam_id' => 'exams', 'academic_year_id' => 'academic_years', 'class_id' => 'classes'] as $field => $table) {
            $rules[$field] = ['nullable', 'integer', Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id())];
        }

return $rules;
    }
}

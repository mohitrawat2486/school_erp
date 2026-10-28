<?php

namespace App\Http\Requests;

use App\Enums\ExamStatus;
use App\Enums\ExamTermStatus;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExamIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('exam.view');
    }

    public function rules(): array
    {
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum($this->routeIs('exam-terms.*') ? ExamTermStatus::class : ExamStatus::class)]];
        foreach (['academic_year_id' => 'academic_years', 'class_id' => 'classes', 'exam_term_id' => 'exam_terms'] as $field => $table) {
            $rules[$field] = ['nullable', 'integer', Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id())];
        }

return $rules;
    }
}

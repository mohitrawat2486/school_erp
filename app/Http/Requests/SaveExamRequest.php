<?php

namespace App\Http\Requests;

use App\Models\Exam;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($exam = $this->route('exam')) ? $this->user()->can('update', $exam) : $this->user()->can('create', Exam::class);
    }

    public function rules(): array
    {
        $rules = ['name' => ['required', 'string', 'max:100'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'school_id' => ['prohibited'], 'status' => ['prohibited'], 'id' => ['prohibited']];
        foreach (['academic_year_id' => 'academic_years', 'exam_term_id' => 'exam_terms', 'class_id' => 'classes'] as $field => $table) {
            $rules[$field] = $this->route('exam') ? ['prohibited'] : ['required', 'integer', Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id())];
        }

return $rules;
    }
}

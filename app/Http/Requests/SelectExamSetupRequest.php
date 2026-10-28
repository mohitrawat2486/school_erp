<?php

namespace App\Http\Requests;

use App\Models\Exam;
use App\Models\ExamTerm;
use Illuminate\Foundation\Http\FormRequest;

class SelectExamSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', $this->routeIs('exam-terms.*') ? ExamTerm::class : Exam::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], ...array_fill_keys(['academic_year_id', 'exam_term_id', 'class_id'], ['nullable', 'integer', 'min:1'])];
    }
}

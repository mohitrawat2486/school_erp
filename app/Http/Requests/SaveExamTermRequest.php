<?php

namespace App\Http\Requests;

use App\Models\ExamTerm;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExamTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($term = $this->route('exam_term')) ? $this->user()->can('update', $term) : $this->user()->can('create', ExamTerm::class);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'academic_year_id' => $this->route('exam_term') ? ['prohibited'] : ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', app(CurrentSchool::class)->id())->whereNull('deleted_at')], 'school_id' => ['prohibited'], 'status' => ['prohibited'], 'id' => ['prohibited']];
    }
}

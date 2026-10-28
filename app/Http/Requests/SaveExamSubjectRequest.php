<?php

namespace App\Http\Requests;

use App\Models\ExamSubject;
use App\Support\CurrentSchool;
use App\Support\ExamMarksConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveExamSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('exam_subject');

        return $record ? $this->user()->can('update', $record) : $this->user()->can('create', ExamSubject::class) && $this->user()->can('update', $this->route('exam'));
    }

    public function rules(): array
    {
        return [...ExamMarksConfiguration::rules(), 'class_subject_id' => $this->route('exam_subject') ? ['prohibited'] : ['required', 'integer', Rule::exists('class_subjects', 'id')->where('school_id', app(CurrentSchool::class)->id())], ...array_fill_keys(['school_id', 'id', 'status', 'exam_id', 'academic_year_id', 'class_id', 'subject_id'], ['prohibited'])];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn ($v) => ExamMarksConfiguration::check($v));
    }
}

<?php

namespace App\Http\Requests;

use App\Models\ExamSubject;
use Illuminate\Foundation\Http\FormRequest;

class SelectExamSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ExamSubject::class) && $this->user()->can('update', $this->route('exam'));
    }

    public function rules(): array
    {
        return ['class_subject_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100']];
    }
}

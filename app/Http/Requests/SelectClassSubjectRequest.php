<?php

namespace App\Http\Requests;

use App\Models\ClassSubject;
use Illuminate\Foundation\Http\FormRequest;

class SelectClassSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('class-subjects.create') ? 'create' : 'viewAny', ClassSubject::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'academic_year_id' => ['nullable', 'integer'], 'class_id' => ['nullable', 'integer'], 'subject_id' => ['nullable', 'integer']];
    }
}

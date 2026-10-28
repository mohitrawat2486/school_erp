<?php

namespace App\Http\Requests;

use App\Enums\SubjectStatus;
use App\Enums\SubjectType;
use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Subject::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(SubjectStatus::class)], 'type' => ['nullable', Rule::enum(SubjectType::class)]];
    }
}

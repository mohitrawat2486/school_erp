<?php

namespace App\Http\Requests;

use App\Models\StudentEnrollment;
use Illuminate\Foundation\Http\FormRequest;

class SelectEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', StudentEnrollment::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], ...array_fill_keys(StudentEnrollment::CONTEXT, ['nullable', 'integer', 'min:1'])];
    }
}

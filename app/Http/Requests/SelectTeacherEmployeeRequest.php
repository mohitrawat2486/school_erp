<?php

namespace App\Http\Requests;

use App\Models\TeacherProfile;
use Illuminate\Foundation\Http\FormRequest;

class SelectTeacherEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TeacherProfile::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'employee_id' => ['nullable', 'integer']];
    }
}

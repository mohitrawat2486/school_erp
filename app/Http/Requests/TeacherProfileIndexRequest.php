<?php

namespace App\Http\Requests;

use App\Enums\TeacherProfileStatus;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherProfileIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', TeacherProfile::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(TeacherProfileStatus::class)]];
    }
}

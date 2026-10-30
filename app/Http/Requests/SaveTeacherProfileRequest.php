<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Models\TeacherProfile;
use App\Support\CurrentSchool;
use App\Support\TeacherWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('teacher_profile');

        return $profile ? $this->user()->can('update', $profile) : $this->user()->can('create', TeacherProfile::class);
    }

    public function rules(): array
    {
        $rules = ['qualification' => ['nullable', 'string', 'max:1000'], 'specialization' => ['nullable', 'string', 'max:255'], 'experience' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,99.99'], 'school_id' => ['prohibited'], 'status' => ['prohibited'], 'id' => ['prohibited'], 'user_id' => ['prohibited']];
        $rules['employee_id'] = $this->route('teacher_profile') ? ['prohibited'] : ['required', 'integer', Rule::exists('employees', 'id')->where('school_id', app(CurrentSchool::class)->id())->where('status', EmployeeStatus::Active->value), Rule::unique('teacher_profiles', 'employee_id')];

        return $rules + TeacherWorkspace::rules();
    }
}

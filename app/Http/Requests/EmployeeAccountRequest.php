<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('managed_user'));
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['q' => ['nullable', 'string', 'max:100']];
        }

        return ['employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('school_id', app(CurrentSchool::class)->id())->where('status', EmployeeStatus::Active->value), Rule::unique('users', 'employee_id')->ignore($this->route('managed_user')->id)], 'school_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}

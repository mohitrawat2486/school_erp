<?php

namespace App\Http\Requests;

use App\Enums\EmployeeType;
use App\Models\Employee;
use App\Support\CurrentSchool;
use App\Support\RasterImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee ? $this->user()->can('update', $employee) : $this->user()->can('create', Employee::class);
    }

    public function rules(): array
    {
        $unique = Rule::unique('employees', 'employee_number')->where('school_id', app(CurrentSchool::class)->id());
        if ($employee = $this->route('employee')) {
            $unique->ignore($employee->id);
        }

        return [
            'employee_number' => ['required', 'string', 'max:50', $unique],
            'first_name' => ['required', 'string', 'max:100'], 'middle_name' => ['nullable', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
            'employee_type' => ['required', Rule::enum(EmployeeType::class)], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'], 'alternate_phone' => ['nullable', 'string', 'max:30'], 'gender' => ['nullable', Rule::in(array_keys(Employee::GENDERS))],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'before:joining_date'],
            'joining_date' => ['required', 'date_format:Y-m-d'], 'designation' => ['nullable', 'string', 'max:255'], 'department' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
            'photo' => RasterImageRules::rules(), 'school_id' => ['prohibited'], 'status' => ['prohibited'], 'photo_path' => ['prohibited'], 'id' => ['prohibited'], 'user_id' => ['prohibited'],
        ];
    }
}

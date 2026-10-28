<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Enums\EmployeeType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Employee::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(EmployeeStatus::class)], 'type' => ['nullable', Rule::enum(EmployeeType::class)]];
    }
}

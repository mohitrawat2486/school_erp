<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['permissions' => $this->input('permissions', [])]);
    }

    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role ? $this->user()->can('update', $role) : $this->user()->can('create', Role::class);
    }

    public function rules(): array
    {
        $unique = Rule::unique('roles', 'name')->where('school_id', app(CurrentSchool::class)->id());
        if ($role = $this->route('role')) {
            $unique->ignore($role->id);
        }

        return ['name' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/', $unique], 'permissions' => ['sometimes', 'array', 'max:200'], 'permissions.*' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')], 'school_id' => ['prohibited'], 'id' => ['prohibited']];
    }
}

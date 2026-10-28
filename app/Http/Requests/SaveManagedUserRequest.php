<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveManagedUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['roles' => $this->input('roles', [])]);
    }

    public function authorize(): bool
    {
        $target = $this->route('managed_user');

        return $target ? $this->user()->can('update', $target) : $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $target = $this->route('managed_user');
        $unique = Rule::unique('users', 'email');
        if ($target) {
            $unique->ignore($target->id);
        }

        return [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', $unique],
            'password' => [$target ? 'nullable' : 'required', 'string', 'min:12', 'max:255', 'confirmed'],
            'is_active' => ['required', 'boolean'], 'roles' => ['sometimes', 'array', 'max:100'],
            'roles.*' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')->where('school_id', app(CurrentSchool::class)->id())],
            'employee_id' => ['prohibited'], 'school_id' => ['prohibited'], 'id' => ['prohibited'], 'remember_token' => ['prohibited'], 'email_verified_at' => ['prohibited'],
        ];
    }
}

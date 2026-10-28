<?php

namespace App\Http\Requests;

use App\Enums\GuardianRelationship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStudentGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('student')) && $this->user()->can('update', $this->route('guardian'));
    }

    public function rules(): array
    {
        return ['relationship' => ['required', Rule::enum(GuardianRelationship::class)], 'is_primary' => ['required', 'boolean'], 'is_emergency_contact' => ['required', 'boolean'], 'school_id' => ['prohibited'], 'student_id' => ['prohibited'], 'guardian_id' => ['prohibited'], 'status' => ['prohibited'], 'id' => ['prohibited']];
    }
}

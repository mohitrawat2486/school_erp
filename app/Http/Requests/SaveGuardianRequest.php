<?php

namespace App\Http\Requests;

use App\Models\Guardian;
use Illuminate\Foundation\Http\FormRequest;

class SaveGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($record = $this->route('guardian')) ? $this->user()->can('update', $record) : $this->user()->can('create', Guardian::class);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'], 'alternate_phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
            'school_id' => ['prohibited'], 'status' => ['prohibited'], 'id' => ['prohibited'], 'student_id' => ['prohibited'],
        ];
    }
}

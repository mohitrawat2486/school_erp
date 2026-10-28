<?php

namespace App\Http\Requests;

use App\Support\CurrentSchool;
use App\Support\RasterImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', app(CurrentSchool::class)->get());
    }

    public function rules(): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('schools', 'code')->ignore(app(CurrentSchool::class)->id())],
            'name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone:all'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => RasterImageRules::rules(),
            'school_id' => ['prohibited'], 'id' => ['prohibited'], 'is_active' => ['prohibited'], 'logo_path' => ['prohibited'],
        ];
        foreach (['short_name', 'affiliation_number', 'board', 'phone', 'alternate_phone', 'address_line_1', 'address_line_2', 'city', 'state', 'country', 'postal_code'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}

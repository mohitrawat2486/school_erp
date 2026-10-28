<?php

namespace App\Http\Requests;

use App\Models\SchoolClass;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $schoolClass = $this->route('school_class');

        return $schoolClass ? $this->user()->can('update', $schoolClass) : $this->user()->can('create', SchoolClass::class);
    }

    public function rules(): array
    {
        $rules = ['name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:50', 'alpha_dash'], 'sort_order' => ['required', 'integer', 'between:0,4294967295'], 'school_id' => ['prohibited'], 'id' => ['prohibited'], 'status' => ['prohibited'], 'academic_year_id' => ['prohibited'], 'section_id' => ['prohibited']];
        foreach (['name', 'code'] as $field) {
            $unique = Rule::unique('classes', $field)->where('school_id', app(CurrentSchool::class)->id());
            if ($schoolClass = $this->route('school_class')) {
                $unique->ignore($schoolClass->id);
            }
            $rules[$field][] = $unique;
        }

        return $rules;
    }
}

<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class ClassSetupRules
{
    public static function creation(bool $requireYear = false): array
    {
        $school = app(CurrentSchool::class)->id();

        return [
            'sections' => ['sometimes', 'array', 'max:50'],
            'sections.*' => ['array:name,capacity'],
            'sections.*.name' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'sections.*.capacity' => ['nullable', 'integer', 'between:1,65535'],
            'subject_ids' => ['sometimes', 'array', 'max:500'],
            'subject_ids.*' => ['required', 'integer', 'distinct', Rule::exists('subjects', 'id')->where('school_id', $school)->where('status', 'ACTIVE')],
            'academic_year_id' => [$requireYear ? 'required' : 'required_with:subject_ids', 'nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $school)->whereNull('deleted_at')->where('is_current', true)->where('status', 'ACTIVE')],
        ];
    }
}

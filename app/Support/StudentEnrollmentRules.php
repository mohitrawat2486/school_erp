<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class StudentEnrollmentRules
{
    public static function rules(array $input): array
    {
        $saving = data_get($input, 'enrollment.action') === 'save';
        $manual = $saving && data_get($input, 'enrollment.roll_mode') === 'manual';

        return [
            'enrollment' => ['nullable', 'array:action,academic_year_id,class_id,section_id,roll_mode,roll_number,joined_at,revision,correct_placement,reactivate'],
            'enrollment.action' => ['required_with:enrollment', Rule::in(['keep', 'save'])],
            'enrollment.academic_year_id' => [$saving ? 'required' : 'nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', app(CurrentSchool::class)->id())->whereNull('deleted_at')],
            'enrollment.class_id' => [$saving ? 'required' : 'exclude', 'integer', Rule::exists('classes', 'id')->where('school_id', app(CurrentSchool::class)->id())],
            'enrollment.section_id' => [$saving ? 'required' : 'exclude', 'integer', Rule::exists('sections', 'id')->where('school_id', app(CurrentSchool::class)->id())],
            'enrollment.roll_mode' => [$saving ? 'required' : 'exclude', Rule::in(['auto', 'manual'])],
            'enrollment.roll_number' => [$manual ? 'required' : 'exclude', 'integer', 'between:1,4294967295'],
            'enrollment.joined_at' => [$saving ? 'required' : 'exclude', 'date_format:Y-m-d'],
            'enrollment.revision' => [$saving ? 'required' : 'exclude', 'string', 'size:64'],
            'enrollment.correct_placement' => [$saving ? 'nullable' : 'exclude', 'boolean'],
            'enrollment.reactivate' => [$saving ? 'nullable' : 'exclude', 'boolean'],
        ];
    }
}

<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class StudentProgressionRules
{
    public static function source(): array
    {
        return array_fill_keys(['source_academic_year_id', 'source_class_id', 'source_section_id'], ['required', 'integer', 'min:1']);
    }

    public static function review(): array
    {
        return self::source() + [
            'operation_key' => ['required', 'uuid'],
            'target_academic_year_id' => ['required', 'integer', 'min:1'],
            'target_class_id' => ['required', 'integer', 'min:1'],
            'target_section_id' => ['required', 'integer', 'min:1'],
            'joined_at' => ['required', 'date_format:Y-m-d'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*' => ['required', 'array:source_enrollment_id,action,roll_number,target_section_id,notes'],
            'rows.*.source_enrollment_id' => ['required', 'integer', 'min:1', 'distinct'],
            'rows.*.action' => ['required', Rule::in(['PROMOTE', 'REPEAT'])],
            'rows.*.roll_number' => ['nullable', 'integer', 'between:1,4294967295'],
            'rows.*.target_section_id' => ['nullable', 'integer', 'min:1', 'required_if:rows.*.action,REPEAT'],
            'rows.*.notes' => ['nullable', 'string', 'max:500'],
            'school_id' => ['prohibited'], 'actor_id' => ['prohibited'], 'mode' => ['prohibited'],
        ];
    }
}

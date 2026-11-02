<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class StudentDepartureRules
{
    public static function rules(array $input): array
    {
        $active = in_array(data_get($input, 'departure.action'), ['WITHDRAW', 'TRANSFER'], true);

        return [
            'departure' => ['nullable', 'array:action,academic_year_id,revision,operation_key,departure_date,reason,destination_school,confirmed'],
            'departure.action' => ['required_with:departure', Rule::in(['keep', 'WITHDRAW', 'TRANSFER'])],
            'departure.academic_year_id' => [$active ? 'required' : 'exclude', 'integer', Rule::exists('academic_years', 'id')->where('school_id', app(CurrentSchool::class)->id())->whereNull('deleted_at')],
            'departure.revision' => [$active ? 'required' : 'exclude', 'string', 'size:64'],
            'departure.operation_key' => [$active ? 'required' : 'exclude', 'uuid'],
            'departure.departure_date' => [$active ? 'required' : 'exclude', 'date_format:Y-m-d', 'before_or_equal:today'],
            'departure.reason' => [$active ? 'required' : 'exclude', 'string', 'max:500'],
            'departure.destination_school' => [$active && data_get($input, 'departure.action') === 'TRANSFER' ? 'nullable' : 'exclude', 'string', 'max:255'],
            'departure.confirmed' => [$active ? 'accepted' : 'exclude'],
        ];
    }
}

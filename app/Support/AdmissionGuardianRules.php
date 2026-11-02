<?php

namespace App\Support;

use App\Enums\GuardianRelationship;
use Illuminate\Validation\Rule;

final class AdmissionGuardianRules
{
    public static function rules(bool $creating = true): array
    {
        if (! $creating) {
            return array_fill_keys(['guardian_mode', 'guardian_id', 'guardian', 'guardian_relationship', 'guardian_emergency'], ['prohibited']);
        }

        return [
            'guardian_mode' => ['nullable', Rule::in(['none', 'new', 'existing'])],
            'guardian_id' => ['exclude_unless:guardian_mode,existing', 'required', 'integer', Rule::exists('guardians', 'id')->where('school_id', app(CurrentSchool::class)->id())->where('status', 'ACTIVE')],
            'guardian_relationship' => ['exclude_unless:guardian_mode,new,existing', 'required', Rule::enum(GuardianRelationship::class)],
            'guardian_emergency' => ['exclude_unless:guardian_mode,new,existing', 'required', 'boolean'],
            'guardian' => ['exclude_unless:guardian_mode,new', 'required', 'array:first_name,last_name,phone,alternate_phone,email,occupation,address,same_address'],
            'guardian.first_name' => ['exclude_unless:guardian_mode,new', 'required', 'string', 'max:100'],
            'guardian.last_name' => ['exclude_unless:guardian_mode,new', 'nullable', 'string', 'max:100'],
            'guardian.phone' => ['exclude_unless:guardian_mode,new', 'required', 'string', 'max:30'],
            'guardian.alternate_phone' => ['exclude_unless:guardian_mode,new', 'nullable', 'string', 'max:30'],
            'guardian.email' => ['exclude_unless:guardian_mode,new', 'nullable', 'email', 'max:255'],
            'guardian.occupation' => ['exclude_unless:guardian_mode,new', 'nullable', 'string', 'max:255'],
            'guardian.same_address' => ['exclude_unless:guardian_mode,new', 'required', 'boolean'],
            'guardian.address' => ['exclude_unless:guardian_mode,new', 'nullable', 'string', 'max:2000'],
        ];
    }
}

<?php

namespace App\Support;

use App\Enums\GuardianRelationship;
use App\Models\Student;
use Illuminate\Validation\Rule;

final class GuardianEditorRules
{
    public static function rules(array $data, ?Student $student = null): array
    {
        $limit = max(3, $student?->guardianLinks()->where('status', 'ACTIVE')->count() ?? 0);
        $enabled = in_array($data['guardians_present'] ?? null, [1, '1'], true);
        $rules = [
            'guardians_present' => ['nullable', Rule::in([1])],
            'guardians' => [$enabled ? 'present' : 'prohibited', 'array', 'max:'.$limit],
            'primary_guardian' => ['nullable', 'string', 'max:6'],
            'guardian_setup_revision' => [$enabled && $student?->exists ? 'required' : 'nullable', 'string', 'size:64'],
            'confirm_guardian_removals' => ['nullable', 'boolean'],
            'guardians.*' => ['array:kind,guardian_id,revision,edit_contact,relationship,is_emergency_contact,details'],
            'guardians.*.kind' => ['required', Rule::in(['new', 'existing'])],
            'guardians.*.guardian_id' => ['nullable', 'integer'],
            'guardians.*.revision' => ['nullable', 'string', 'size:64'],
            'guardians.*.edit_contact' => ['nullable', 'boolean'],
            'guardians.*.relationship' => ['required', Rule::enum(GuardianRelationship::class)],
            'guardians.*.is_emergency_contact' => ['required', 'boolean'],
            'guardians.*.details' => ['nullable', 'array:first_name,last_name,phone,alternate_phone,email,occupation,address,same_address'],
        ];
        $rows = is_array($data['guardians'] ?? null) ? $data['guardians'] : [];
        foreach (array_slice($rows, 0, $limit + 1, true) as $key => $row) {
            if (! ctype_digit((string) $key) || strlen((string) $key) > 6 || ! is_array($row)) {
                continue;
            }
            $prefix = 'guardians.'.$key;
            if (($row['kind'] ?? null) === 'existing') {
                $rules[$prefix.'.guardian_id'] = ['required', 'integer', Rule::exists('guardians', 'id')->where('school_id', app(CurrentSchool::class)->id())];
                $rules[$prefix.'.revision'] = ['required', 'string', 'size:64'];
            } else {
                $rules[$prefix.'.guardian_id'] = ['prohibited'];
            }
            $editing = ($row['kind'] ?? null) === 'new' || filter_var($row['edit_contact'] ?? false, FILTER_VALIDATE_BOOL);
            $rules[$prefix.'.details'] = [$editing ? 'required' : 'exclude', 'array:first_name,last_name,phone,alternate_phone,email,occupation,address,same_address'];
            if ($editing) {
                foreach (['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'], 'phone' => ['required', 'string', 'max:30'], 'alternate_phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'occupation' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'], 'same_address' => ['required', 'boolean']] as $field => $fieldRules) {
                    $rules[$prefix.'.details.'.$field] = $fieldRules;
                }
            }
        }

        return $rules;
    }
}

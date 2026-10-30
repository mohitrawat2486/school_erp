<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class ClassTeacherRules
{
    public static function rules(): array
    {
        $school = app(CurrentSchool::class)->id();

        return [
            'teacher_changes' => ['sometimes', 'array', 'max:500'],
            'teacher_changes.*' => ['array:section_key,subject_id,teacher_id'],
            'teacher_changes.*.section_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'teacher_changes.*.subject_id' => ['nullable', 'integer', 'min:1', Rule::exists('subjects', 'id')->where('school_id', $school)->where('status', 'ACTIVE')],
            'teacher_changes.*.teacher_id' => ['present', 'nullable', 'integer', 'min:1', Rule::exists('teacher_profiles', 'id')->where('school_id', $school)->where('status', 'ACTIVE')->where(fn (Builder $query) => $query->whereIn('employee_id', fn (Builder $employees) => $employees->select('id')->from('employees')->where('school_id', $school)->where('status', 'ACTIVE')))],
        ];
    }

    public static function messages(): array
    {
        return [
            'teacher_changes.array' => 'Reload the teacher panel and review your choices.',
            'teacher_changes.max' => 'Choose no more than 500 teacher assignments in one save.',
            'teacher_changes.*.teacher_id.exists' => 'Choose an active teacher from your school.',
            'teacher_changes.*.subject_id.exists' => 'Choose an active subject from your school before assigning its teacher.',
        ];
    }
}

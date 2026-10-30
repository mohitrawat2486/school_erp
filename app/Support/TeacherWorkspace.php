<?php

namespace App\Support;

use App\Enums\AssignmentType;
use App\Enums\TeacherAssignmentStatus;
use App\Enums\TeacherProfileStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class TeacherWorkspace
{
    public static function rules(): array
    {
        $rules = [];
        foreach (['directory' => ['q', 'status', 'page', 'tables'], 'list' => ['q', 'status', 'assignment_type', 'academic_year_id', 'teacher_id', 'class_id', 'page', 'tables']] as $prefix => $keys) {
            $rules[$prefix] = ['sometimes', 'array:'.implode(',', $keys)];
            $rules[$prefix.'.q'] = 'nullable|string|max:100';
            $rules[$prefix.'.status'] = ['nullable', Rule::enum($prefix === 'directory' ? TeacherProfileStatus::class : TeacherAssignmentStatus::class)];
            $rules[$prefix.'.page'] = 'nullable|integer|between:1,100000';
            $rules[$prefix.'.tables'] = 'sometimes|array:page';
            $rules[$prefix.'.tables.page'] = 'sometimes|array:search,length';
            $rules[$prefix.'.tables.page.search'] = 'nullable|string|max:100';
            $rules[$prefix.'.tables.page.length'] = ['sometimes', 'integer', Rule::in([10, 20, 25, 50, 100])];
        }
        $rules['list.assignment_type'] = ['nullable', Rule::enum(AssignmentType::class)];
        foreach (['academic_year_id', 'teacher_id', 'class_id'] as $field) {
            $rules['list.'.$field] = 'nullable|integer|min:1';
        }

        return $rules;
    }

    /** Only structured local query state is accepted; no return URL is trusted. */
    public static function context(Request $request): array
    {
        $data = Validator::make($request->only(['directory', 'list']), self::rules())->validate();

        return array_filter($data, fn ($value) => $value !== []);
    }

    public static function directory(Request $request): array
    {
        return Validator::make(['directory' => $request->only(['q', 'status', 'page', 'tables'])], self::rules())->validate()['directory'];
    }

    public static function listing(Request $request): array
    {
        return Validator::make(['list' => $request->only(['q', 'status', 'assignment_type', 'academic_year_id', 'teacher_id', 'class_id', 'page', 'tables'])], self::rules())->validate()['list'];
    }
}

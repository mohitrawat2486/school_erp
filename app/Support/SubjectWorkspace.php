<?php

namespace App\Support;

use App\Enums\ClassSubjectStatus;
use App\Enums\SubjectStatus;
use App\Enums\SubjectType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SubjectWorkspace
{
    public static function rules(): array
    {
        $rules = [];
        foreach (['catalog' => ['q', 'status', 'type', 'page', 'tables'], 'mapping' => ['q', 'status', 'academic_year_id', 'class_id', 'subject_id', 'page', 'tables']] as $prefix => $keys) {
            $rules[$prefix] = ['sometimes', 'array:'.implode(',', $keys)];
            $rules[$prefix.'.q'] = 'nullable|string|max:100';
            $rules[$prefix.'.status'] = ['nullable', Rule::enum($prefix === 'catalog' ? SubjectStatus::class : ClassSubjectStatus::class)];
            $rules[$prefix.'.page'] = 'nullable|integer|between:1,100000';
            $rules[$prefix.'.tables'] = 'sometimes|array:page';
            $rules[$prefix.'.tables.page'] = 'sometimes|array:search,length';
            $rules[$prefix.'.tables.page.search'] = 'nullable|string|max:100';
            $rules[$prefix.'.tables.page.length'] = ['sometimes', 'integer', Rule::in([10, 20, 25, 50, 100])];
        }
        $rules['catalog.type'] = ['nullable', Rule::enum(SubjectType::class)];
        foreach (['academic_year_id', 'class_id', 'subject_id'] as $field) {
            $rules['mapping.'.$field] = 'nullable|integer|min:1';
        }

        return $rules;
    }

    public static function context(Request $request): array
    {
        return array_filter(Validator::make($request->only(['catalog', 'mapping']), self::rules())->validate(), fn ($value) => $value !== []);
    }

    public static function catalog(Request $request): array
    {
        return Validator::make(['catalog' => $request->only(['q', 'status', 'type', 'page', 'tables'])], self::rules())->validate()['catalog'];
    }

    public static function mapping(Request $request): array
    {
        return Validator::make(['mapping' => $request->only(['q', 'status', 'academic_year_id', 'class_id', 'subject_id', 'page', 'tables'])], self::rules())->validate()['mapping'];
    }
}

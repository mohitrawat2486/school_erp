<?php

namespace App\Http\Requests;

use App\Models\Section;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SectionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Section::class);
    }

    public function rules(): array
    {
        $school = app(CurrentSchool::class)->id();

        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'PENDING'])],
            'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')->where('school_id', $school)],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $school)->whereNull('deleted_at')],
            'tables' => ['sometimes', 'array', 'max:10'],
            'tables.*' => ['array:search,length'],
            'tables.*.search' => ['nullable', 'string', 'max:100'],
            'tables.*.length' => ['sometimes', 'integer', Rule::in([10, 20, 25, 50, 100])],
        ];
    }
}

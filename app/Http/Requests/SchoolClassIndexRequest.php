<?php

namespace App\Http\Requests;

use App\Models\SchoolClass;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SchoolClassIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', SchoolClass::class);
    }

    public function rules(): array
    {
        $school = app(CurrentSchool::class)->id();

        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'PENDING'])],
            'view' => ['nullable', Rule::in(['classes', 'sections']), Rule::prohibitedIf(! $this->user()->can('section.view') && $this->input('view') === 'sections')],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $school)->whereNull('deleted_at')],
            'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')->where('school_id', $school)],
            'subject_id' => ['nullable', 'integer', Rule::prohibitedIf(! $this->user()->can('subject.view')), Rule::exists('subjects', 'id')->where('school_id', $school)],
            'tables' => ['sometimes', 'array', 'max:10'],
            'tables.*' => ['array:search,length'],
            'tables.*.search' => ['nullable', 'string', 'max:100'],
            'tables.*.length' => ['sometimes', 'integer', Rule::in([10, 20, 25, 50, 100])],
        ];
    }
}

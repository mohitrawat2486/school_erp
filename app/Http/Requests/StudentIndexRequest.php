<?php

namespace App\Http\Requests;

use App\Enums\StudentStatus;
use App\Models\Student;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Student::class);
    }

    public function rules(): array
    {
        return [
            'tables' => ['sometimes', 'array', 'max:10'],
            'tables.*' => ['array:search,length'],
            'tables.*.search' => ['nullable', 'string', 'max:100'],
            'tables.*.length' => ['sometimes', 'integer', Rule::in([10, 20, 25, 50, 100])],

            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(StudentStatus::class)],
            'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')->where('school_id', app(CurrentSchool::class)->id())],
            'section_id' => [
                'nullable', 'integer', Rule::prohibitedIf(! $this->filled('class_id')),
                Rule::exists('sections', 'id')->where('school_id', app(CurrentSchool::class)->id())->where('class_id', $this->integer('class_id'))->where('status', 'ACTIVE'),
            ],
        ];
    }
}

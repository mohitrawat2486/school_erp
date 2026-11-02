<?php

namespace App\Http\Requests;

use App\Models\Student;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('student.import') && $this->user()->can('create', Student::class);
    }

    public function rules(): array
    {
        if ($this->routeIs('students.import.store')) {
            return ['review_token' => ['required', 'uuid'], 'chunk' => ['required', 'integer', 'between:0,19'], 'include_warnings' => ['required', 'boolean']];
        }

        return [
            'rows' => ['required', 'array', 'list', 'min:1', 'max:'.config('student-import.max_rows')],
            'rows.*' => ['required', 'array', 'list', 'min:1', 'max:'.config('student-import.max_columns')],
            'rows.*.*' => ['nullable', 'string', 'max:2000'],
            'mappings' => ['required', 'array', 'list', 'min:1', 'max:'.config('student-import.max_columns')],
            'mappings.*' => ['nullable', 'string', Rule::in(array_keys(config('student-import.fields')))],
            'settings' => ['required', 'array:date_format,admission_date,academic_year_id'],
            'settings.date_format' => ['required', Rule::in(['d/m/Y', 'm/d/Y', 'Y-m-d'])],
            'settings.admission_date' => ['nullable', 'date_format:Y-m-d'],
            'settings.academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', app(CurrentSchool::class)->id())->where('status', 'ACTIVE')->whereNull('deleted_at')],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->isEmpty() || $this->routeIs('students.import.store')) {
                return;
            }
            if (strlen(json_encode($this->input('rows'))) > config('student-import.max_bytes')) {
                $validator->errors()->add('rows', 'Paste at most 2 MB of data at a time.');
            }
            $mapped = array_filter($this->input('mappings'));
            if (! $mapped) {
                $validator->errors()->add('mappings', 'Map at least one Student field.');
            }
            if (count($mapped) !== count(array_unique($mapped))) {
                $validator->errors()->add('mappings', 'Each Student field can only be mapped once.');
            }
            if (in_array('student_name', $mapped, true) && array_intersect(['first_name', 'middle_name', 'last_name'], $mapped)) {
                $validator->errors()->add('mappings', 'Use the full name column or separate name columns, not both.');
            }
            foreach (['admission_number', 'aadhaar_number'] as $required) {
                if (! in_array($required, $mapped, true)) {
                    $validator->errors()->add('mappings', 'Map '.config('student-import.fields.'.$required.'.label').'.');
                }
            }
            if (! array_intersect(['student_name', 'first_name'], $mapped)) {
                $validator->errors()->add('mappings', 'Map Student full name or First name.');
            }
            if (! in_array('admission_date', $mapped, true) && ! $this->filled('settings.admission_date')) {
                $validator->errors()->add('settings.admission_date', 'Map Admission date or choose a default admission date.');
            }
        }];
    }
}

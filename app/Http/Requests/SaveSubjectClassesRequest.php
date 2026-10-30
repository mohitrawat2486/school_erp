<?php

namespace App\Http\Requests;

use App\Models\ClassSubject;
use App\Support\CurrentSchool;
use App\Support\SubjectWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSubjectClassesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ClassSubject::class);
    }

    public static function membershipRules(): array
    {
        $school = app(CurrentSchool::class)->id();

        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $school)->whereNull('deleted_at')->whereIn('status', ['DRAFT', 'ACTIVE'])],
            'class_ids' => ['present', 'array', 'max:500'],
            'class_ids.*' => ['required', 'integer', 'distinct', Rule::exists('classes', 'id')->where('school_id', $school)],
            'setup_revision' => ['required', 'string', 'size:64'],
            'confirm_removals' => ['sometimes', 'boolean'],
            'school_id' => ['prohibited'], 'subject_id' => ['prohibited'], 'status' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('classes_present') && ! $this->has('class_ids')) {
            $this->merge(['class_ids' => []]);
        }
    }

    public function rules(): array
    {
        return self::membershipRules() + SubjectWorkspace::rules();
    }

    public function messages(): array
    {
        return ['academic_year_id.exists' => 'Choose a draft or active academic year in your school.', 'class_ids.*.exists' => 'Choose classes from your school.', 'setup_revision.required' => 'Reload the class checklist before saving.'];
    }
}

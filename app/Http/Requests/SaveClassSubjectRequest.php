<?php

namespace App\Http\Requests;

use App\Enums\AcademicYearStatus;
use App\Enums\ClassStatus;
use App\Enums\SubjectStatus;
use App\Models\ClassSubject;
use App\Support\CurrentSchool;
use App\Support\MarksConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveClassSubjectRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(MarksConfiguration::merge($this->all(), $this->route('class_subject')?->only(MarksConfiguration::FIELDS) ?? []));
    }

    public function authorize(): bool
    {
        $allocation = $this->route('class_subject');

        return $allocation ? $this->user()->can('update', $allocation) : $this->user()->can('create', ClassSubject::class);
    }

    public function rules(): array
    {
        $rules = [...MarksConfiguration::rules(), 'school_id' => ['prohibited'], 'id' => ['prohibited'], 'status' => ['prohibited']];
        foreach (['academic_year_id' => 'academic_years', 'class_id' => 'classes', 'subject_id' => 'subjects'] as $field => $table) {
            if ($this->route('class_subject')) {
                $rules[$field] = ['prohibited'];

                continue;
            }
            $exists = Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id());
            if ($field === 'academic_year_id') {
                $exists->whereNull('deleted_at')->whereIn('status', [AcademicYearStatus::Draft->value, AcademicYearStatus::Active->value]);
            } else {
                $exists->where('status', $field === 'class_id' ? ClassStatus::Active->value : SubjectStatus::Active->value);
            }
            $rules[$field] = ['required', 'integer', $exists];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn ($v) => MarksConfiguration::check($v));
    }
}

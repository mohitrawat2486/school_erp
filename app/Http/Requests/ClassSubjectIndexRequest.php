<?php

namespace App\Http\Requests;

use App\Enums\ClassSubjectStatus;
use App\Models\ClassSubject;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassSubjectIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', ClassSubject::class);
    }

    public function rules(): array
    {
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(ClassSubjectStatus::class)]];
        foreach (['academic_year_id' => 'academic_years', 'class_id' => 'classes', 'subject_id' => 'subjects'] as $field => $table) {
            $exists = Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id());
            if ($field === 'academic_year_id') {
                $exists->whereNull('deleted_at');
            } $rules[$field] = ['nullable', 'integer', $exists];
        }

        return $rules;
    }
}

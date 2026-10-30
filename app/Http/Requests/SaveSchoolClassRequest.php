<?php

namespace App\Http\Requests;

use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Support\ClassCode;
use App\Support\ClassSetupRules;
use App\Support\ClassTeacherRules;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSchoolClassRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('teacher_changes'))) {
            try {
                $changes = json_decode($this->input('teacher_changes'), true, 32, JSON_THROW_ON_ERROR);
                if (is_array($changes)) {
                    $this->merge(['teacher_changes' => $changes]);
                }
            } catch (\JsonException) {
                // Leave malformed input for the array validation rule.
            }
        }
        if (is_string($this->input('name'))) {
            $name = trim($this->input('name'));
            $existing = $this->route('school_class');
            $this->merge(['name' => $name, 'code' => $existing && $existing->name === $name ? $existing->code : ClassCode::fromName($name)]);
        }
        if ($this->boolean('sections_present') && ! $this->has('sections')) {
            $this->merge(['sections' => []]);
        }
        if ($this->boolean('subjects_present') && ! $this->has('subject_ids')) {
            $this->merge(['subject_ids' => []]);
        }
        if (is_array($sections = $this->input('sections')) && count($sections) <= 50) {
            foreach ($sections as $key => &$row) {
                if (! is_array($row)) {
                    continue;
                }
                if (array_key_exists('enabled', $row)) {
                    if (! in_array($row['enabled'], [1, '1', true], true)) {
                        unset($sections[$key]);

                        continue;
                    }
                    unset($row['enabled']);
                }
                if (isset($row['name']) && is_string($row['name'])) {
                    $row['name'] = trim($row['name']);
                }
                if (($row['name'] ?? '') === '' && empty($row['capacity'])) {
                    unset($sections[$key]);
                }
            }
            unset($row);
            $this->merge(['sections' => $this->boolean('skip_sections') ? [] : $sections]);
        }
    }

    public function authorize(): bool
    {
        $schoolClass = $this->route('school_class');
        if ($this->filled('teacher_changes') && ! $this->user()->can('create', TeacherAssignment::class)) {
            return false;
        }
        if ($schoolClass) {
            return $this->user()->can('update', $schoolClass)
                && (! $this->has('sections') || $this->user()->can('create', Section::class))
                && (! $this->has('subject_ids') || $this->user()->can('create', ClassSubject::class));
        }

        return $this->user()->can('create', SchoolClass::class)
            && (! $this->filled('sections') || $this->user()->can('create', Section::class))
            && (! $this->filled('subject_ids') || $this->user()->can('create', ClassSubject::class));
    }

    public function rules(): array
    {
        $editing = $this->route('school_class');
        $rules = ['name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:50', 'alpha_dash'], 'sort_order' => ['nullable', 'integer', 'between:0,4294967295'], 'school_id' => ['prohibited'], 'id' => ['prohibited'], 'status' => ['prohibited'], 'section_id' => ['prohibited']];
        $rules += ClassSetupRules::creation(($editing && $this->has('subject_ids')) || $this->filled('teacher_changes'));
        $rules += ClassTeacherRules::rules();
        $rules += ['sections_present' => ['sometimes', 'boolean'], 'subjects_present' => ['sometimes', 'boolean'], 'setup_revision' => [$editing && ($this->has('sections') || $this->has('subject_ids') || $this->filled('teacher_changes')) ? 'required' : 'nullable', 'string', 'size:64']];
        foreach (['name', 'code'] as $field) {
            $unique = Rule::unique('classes', $field)->where('school_id', app(CurrentSchool::class)->id());
            if ($editing) {
                $unique->ignore($editing->id);
            }
            $rules[$field][] = $unique;
        }

        return $rules;
    }

    public function messages(): array
    {
        return ClassTeacherRules::messages() + [
            'code.unique' => 'This name produces a class code already in use. Please choose a different class name.',
            'sections.*.name.distinct' => 'Each section needs a different name.',
            'sections.*.name.required' => 'Enter a name for this section.',
            'sections.*.capacity.between' => 'The student limit must be between 1 and 65,535.',
            'subject_ids.*.exists' => 'Choose an active subject from your school.',
            'academic_year_id.required_with' => 'Set an active current academic year before assigning subjects or teachers.',
            'academic_year_id.exists' => 'The current academic year changed. Reload this page before choosing subjects or teachers.',
            'setup_revision.required' => 'Reload this page before changing the class setup.',
        ];
    }
}

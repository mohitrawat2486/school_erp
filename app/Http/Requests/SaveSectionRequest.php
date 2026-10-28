<?php

namespace App\Http\Requests;

use App\Enums\ClassStatus;
use App\Models\Section;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $section = $this->route('section');

        return $section ? $this->user()->can('update', $section) : $this->user()->can('create', Section::class);
    }

    public function rules(): array
    {
        $section = $this->route('section');
        $classId = $section?->class_id ?? $this->input('class_id');
        $unique = Rule::unique('sections', 'name')->where('school_id', app(CurrentSchool::class)->id())->where('class_id', is_scalar($classId) ? $classId : null);
        if ($section) {
            $unique->ignore($section->id);
        }

        return [
            'name' => ['required', 'string', 'max:50', $unique], 'capacity' => ['nullable', 'integer', 'between:1,65535'],
            'class_id' => $section ? ['prohibited'] : ['required', 'integer', Rule::exists('classes', 'id')->where('school_id', app(CurrentSchool::class)->id())->where('status', ClassStatus::Active->value)],
            'school_id' => ['prohibited'], 'id' => ['prohibited'], 'status' => ['prohibited'], 'academic_year_id' => ['prohibited'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\SectionStatus;
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
        return ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(SectionStatus::class)], 'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')->where('school_id', app(CurrentSchool::class)->id())]];
    }
}

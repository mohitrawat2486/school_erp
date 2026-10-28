<?php

namespace App\Http\Requests;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;

class SelectSectionClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('sections.create') ? 'create' : 'viewAny', Section::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'class_id' => ['nullable', 'integer']];
    }
}

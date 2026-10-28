<?php

namespace App\Http\Requests;

use App\Models\PromotionBatch;
use App\Support\PromotionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PromotionBatch::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'mode' => ['nullable', Rule::in(['PLACEMENT', 'EXIT'])], ...array_fill_keys([...PromotionRules::SOURCE, ...PromotionRules::TARGET, 'source_enrollment_id'], ['nullable', 'integer', 'min:1'])];
    }
}

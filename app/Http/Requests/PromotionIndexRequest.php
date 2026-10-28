<?php

namespace App\Http\Requests;

use App\Models\PromotionBatch;
use Illuminate\Foundation\Http\FormRequest;

class PromotionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', PromotionBatch::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100']];
    }
}

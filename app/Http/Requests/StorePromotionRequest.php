<?php

namespace App\Http\Requests;

use App\Models\PromotionBatch;
use App\Support\PromotionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class StorePromotionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');
        if (is_array($rows) && $this->input('from_selection') === '1') {
            $selected = [];
            foreach ($rows as $row) {
                if (is_array($row) && ($row['selected'] ?? null) === '1') {
                    $selected[] = Arr::only($row, ['source_enrollment_id', 'action', 'roll_number', 'notes']);
                }
            }
            $this->merge(['rows' => $selected]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', PromotionBatch::class);
    }

    public function rules(): array
    {
        return PromotionRules::rules();
    }
}

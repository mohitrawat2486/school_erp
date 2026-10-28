<?php

namespace App\Support;

use App\Enums\PromotionAction;
use Illuminate\Validation\Rule;

final class PromotionRules
{
    public const SOURCE = ['source_academic_year_id', 'source_class_id', 'source_section_id'];

    public const TARGET = ['target_academic_year_id', 'target_class_id', 'target_section_id'];

    public static function rules(): array
    {
        $rules = ['operation_key' => ['required', 'uuid'], 'confirmed' => ['accepted'], 'mode' => ['required', Rule::in(['PLACEMENT', 'EXIT'])], 'joined_at' => ['required_if:mode,PLACEMENT', 'prohibited_if:mode,EXIT', 'nullable', 'date_format:Y-m-d'], 'rows' => ['required', 'array', 'min:1', 'max:100'], 'rows.*' => ['required', 'array:source_enrollment_id,action,roll_number,notes'], 'rows.*.source_enrollment_id' => ['required', 'integer', 'min:1', 'distinct'], 'rows.*.action' => ['required', Rule::enum(PromotionAction::class)], 'rows.*.roll_number' => ['nullable', 'integer', 'between:1,4294967295'], 'rows.*.notes' => ['nullable', 'string', 'max:500'], 'school_id' => ['prohibited'], 'actor_id' => ['prohibited'], 'status' => ['prohibited']];
        foreach (self::SOURCE as $field) {
            $rules[$field] = ['required', 'integer', 'min:1'];
        }foreach (self::TARGET as $field) {
            $rules[$field] = ['required_if:mode,PLACEMENT', 'prohibited_if:mode,EXIT', 'nullable', 'integer', 'min:1'];
        }

return $rules;
    }
}

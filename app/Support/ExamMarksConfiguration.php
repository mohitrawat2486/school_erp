<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

final class ExamMarksConfiguration
{
    public static function rules(): array
    {
        return [...MarksConfiguration::rules(), 'passing_marks' => ['required', 'numeric', 'decimal:0,2', 'between:0,9999.99']];
    }

    public static function check(ValidationValidator $validator): void
    {
        MarksConfiguration::check($validator);
        if ($validator->errors()->isEmpty() && MarksConfiguration::total($validator->getData()) === null) {
            $validator->errors()->add('theory_max_marks', 'Configure theory or practical maximum marks for the exam.');
        }
    }

    public static function validate(array $data, array $base = []): array
    {
        $validator = Validator::make(MarksConfiguration::merge($data, $base), self::rules());
        $validator->after(fn ($v) => self::check($v));

        return $validator->validate();
    }
}

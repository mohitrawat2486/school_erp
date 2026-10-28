<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

final class MarksConfiguration
{
    public const FIELDS = ['theory_max_marks', 'practical_max_marks', 'passing_marks'];

    public static function merge(array $data, array $base = []): array
    {
        return array_replace(array_fill_keys(self::FIELDS, null), Arr::only($base, self::FIELDS), Arr::only($data, self::FIELDS));
    }

    public static function rules(): array
    {
        return array_fill_keys(self::FIELDS, ['nullable', 'numeric', 'decimal:0,2', 'between:0,9999.99']);
    }

    private static function cents(mixed $value): int
    {
        $parts = explode('.', (string) ($value ?? '0'));

        return ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function check(ValidationValidator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }
        $data = self::merge($validator->getData());
        $configured = $data['theory_max_marks'] !== null || $data['practical_max_marks'] !== null;
        $max = self::cents($data['theory_max_marks']) + self::cents($data['practical_max_marks']);
        if ($configured && $max === 0) {
            $validator->errors()->add('theory_max_marks', 'The combined maximum marks must be greater than zero.');
        }
        if ($data['passing_marks'] !== null && (! $configured || self::cents($data['passing_marks']) > $max)) {
            $validator->errors()->add('passing_marks', 'Passing marks require configured maximum marks and cannot exceed their total.');
        }
    }

    public static function validate(array $data, array $base = []): array
    {
        $validator = Validator::make(self::merge($data, $base), self::rules());
        $validator->after(fn ($v) => self::check($v));

        return $validator->validate();
    }

    public static function total(array $data): ?string
    {
        $data = self::merge($data);
        if ($data['theory_max_marks'] === null && $data['practical_max_marks'] === null) {
            return null;
        }
        $max = self::cents($data['theory_max_marks']) + self::cents($data['practical_max_marks']);

        return intdiv($max, 100).'.'.str_pad((string) ($max % 100), 2, '0', STR_PAD_LEFT);
    }
}

<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class ResultMath
{
    // Keeps all percentage multiplication safely within a signed 64-bit integer.
    public const MAX_UNITS = 1000000000000;

    public static function units(mixed $value, string $field = 'marks', int $maximum = self::MAX_UNITS): int
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! preg_match('/^\d{1,11}(?:\.\d{1,2})?$/D', (string) $value)) {
            throw ValidationException::withMessages([$field => 'Use a nonnegative decimal with at most two decimal places.']);
        }
        $parts = explode('.', (string) $value);
        $units = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        if ($units > $maximum) {
            throw ValidationException::withMessages([$field => 'This value exceeds the supported calculation limit.']);
        }

        return $units;
    }

    public static function decimal(int $units): string
    {
        return intdiv($units, 100).'.'.str_pad((string) ($units % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function percentage(mixed $obtained, mixed $maximum): string
    {
        $a = self::units($obtained, 'obtained_marks');
        $b = self::units($maximum, 'total_max_marks');
        if ($b === 0 || $a > $b) {
            throw ValidationException::withMessages(['marks' => 'Maximum marks must be positive and obtained marks cannot exceed them.']);
        }

        return self::decimal(intdiv($a * 10000 + intdiv($b, 2), $b));
    }
}

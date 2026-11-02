<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AadhaarNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{12}\z/', $value)) {
            $fail('Enter a 12-digit Aadhaar number without spaces.');
        }
    }
}

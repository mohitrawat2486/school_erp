<?php

namespace App\Support;

use Illuminate\Support\Str;

final class ClassCode
{
    public static function fromName(string $name): string
    {
        $code = Str::upper(Str::slug(trim($name)));
        if ($code === '') {
            return 'CLASS-'.strtoupper(substr(sha1($name), 0, 8));
        }

        return strlen($code) <= 50 ? $code : substr($code, 0, 41).'-'.strtoupper(substr(sha1($name), 0, 8));
    }
}

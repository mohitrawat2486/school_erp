<?php

namespace App\Support;

final class RasterImageRules
{
    public static function rules(): array
    {
        return ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'];
    }
}

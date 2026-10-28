<?php

namespace App\Services;

use App\Models\School;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportCardContext
{
    public function capture(School $school): array
    {
        abort_unless($school->id === app(CurrentSchool::class)->id(), 403);
        $school = School::findOrFail($school->id);
        $address = implode(', ', array_filter([$school->address_line_1, $school->address_line_2, $school->city, $school->state, $school->postal_code, $school->country], fn ($v) => $v !== null && $v !== ''));
        $logo = null;
        if ($school->logo_path) {
            $disk = Storage::disk('local');
            if (! $disk->exists($school->logo_path)) {
                throw ValidationException::withMessages(['logo' => 'The configured school logo is missing. Resolve school branding before generating results.']);
            }$bytes = $disk->get($school->logo_path);
            $info = @getimagesizefromstring($bytes);
            if (strlen($bytes) > 2 * 1024 * 1024 || ! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw ValidationException::withMessages(['logo' => 'Use a PNG, JPEG or WebP school logo up to 2 MB before generating results.']);
            }$logo = 'data:'.$info['mime'].';base64,'.base64_encode($bytes);
        }

        return ['name' => $school->name, 'address' => $address ?: null, 'logo' => $logo];
    }

    public function logo(array $context): ?string
    {
        $logo = $context['logo'] ?? null;

        return is_string($logo) && preg_match('~^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=]+$~D', $logo) ? $logo : null;
    }
}

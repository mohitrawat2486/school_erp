<?php

namespace App\Support;

use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Auth\Access\AuthorizationException;

final class CurrentSchool
{
    private ?School $school = null;

    public function set(School $school): void
    {
        if (! $school->is_active) {
            throw new AuthorizationException('School is inactive.');
        }
        $this->school = $school;
    }

    public function get(): School
    {
        return $this->school ?? throw new AuthorizationException('School context is required.');
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function academicYear(): ?AcademicYear
    {
        return AcademicYear::where('is_current', true)->first();
    }

    public function clear(): void
    {
        $this->school = null;
    }
}

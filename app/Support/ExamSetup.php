<?php

namespace App\Support;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;

final class ExamSetup
{
    public static function writable(?AcademicYear $year): bool
    {
        return $year && ! $year->trashed() && in_array($year->status, [AcademicYearStatus::Draft, AcademicYearStatus::Active], true);
    }
}
